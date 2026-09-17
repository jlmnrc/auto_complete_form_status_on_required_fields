<?php

namespace Monash\AutoCompleteStatusRequiredFieldsModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

class AutoCompleteStatusRequiredFieldsModule extends AbstractExternalModule
{
    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        // Skip if accessed via randomization form
        if (strpos($_SERVER['REQUEST_URI'], '/Randomization/randomize_record.php') !== false) {
            return;
        }

        $settings = ExternalModules::getProjectSettingsAsArray($this->PREFIX, $project_id);
        $currentCompleteStatus = $_POST[$instrument . '_complete'] ?? null;
        // new in version 2.0
        $checkVerifiedStatus = $settings['check_verified_status']['value'] ?? [];
        // new in version 2.0 - the repeatable entries determine which instruments the
        // module applies to, and provide the (optional) custom logic per instrument
        list($applies, $customLogic, $logicCombination) = $this->getInstrumentSettings($instrument, $project_id);

        // Skip if it's a delete or the instrument isn't covered by any entry
        if (!isset($currentCompleteStatus) || !$applies) {
            return;
        }

        // new in version 2.0 - evaluate custom logic and determine the new status.
        // With combination NONE the required fields check is skipped entirely.
        $customLogicResult = ($customLogic !== '')
            ? $this->evaluateCustomLogic($customLogic, $project_id, $record, $event_id, $instrument, $repeat_instance)
            : null;

        if ($logicCombination === 'none' && $customLogicResult !== null) {
            $newStatus = $customLogicResult ? 2 : 0;
        } else {
            $newStatus = $this->checkRequiredFields($instrument, $checkVerifiedStatus, $record, $project_id, $event_id, $repeat_instance);
            if ($customLogicResult !== null) {
                $newStatus = $this->combineWithCustomLogic($newStatus, $customLogicResult, $logicCombination);
            }
            // If $customLogicResult is null the logic is invalid or not configured;
            // the required fields result is used unchanged (invalid logic is logged).
        }

        if ($newStatus === (int)$currentCompleteStatus) {
            return; // No update needed
        }

        global $Proj;
        $recordIdField = REDCap::getRecordIdField();
        $eventName = REDCap::getEventNames(true, true, $event_id);

        $data = [
            $recordIdField => $record,
            'redcap_event_name' => $eventName,
            $instrument . '_complete' => $newStatus
        ];

        // Add repeating form or event info if applicable
        if ($Proj->isRepeatingForm($event_id, $instrument)) {
            $data['redcap_repeat_instrument'] = $instrument;
            $data['redcap_repeat_instance'] = $repeat_instance;
        } elseif ($Proj->isRepeatingEvent($event_id)) {
            $data['redcap_repeat_instance'] = $repeat_instance;
        }

        $jsonData = json_encode([$data]);
        $saveResponse = REDCap::saveData($project_id, 'json', $jsonData, 'overwrite');

        $errorsExist = (
            (is_array($saveResponse['errors']) && count($saveResponse['errors']) > 0) ||
            (!is_array($saveResponse['errors']) && !empty($saveResponse['errors']))
        );

        if ($errorsExist) {
            $errors = print_r($saveResponse['errors'], true);
            $message = sprintf(
                "The %s could not save %s form status because of the following error(s):\n\n%s",
                $this->getModuleName(),
                $instrument,
                $errors
            );

            error_log($message);
            REDCap::logEvent($this->getModuleName(), $errors, '', $record, $event_id);
        } else {
            // Save to REDCap log
            // Log completion status
            $usingCustomLogic = ($customLogic !== '' && $customLogicResult !== null);
            if ($newStatus === 2) {
                if (!$usingCustomLogic) {
                    $detail = "All required fields in '$instrument' are entered";
                } elseif ($logicCombination === 'none') {
                    $detail = "Custom logic for '$instrument' is met";
                } else {
                    $detail = "Completion conditions (required fields " . strtoupper($logicCombination) . " custom logic) for '$instrument' are met";
                }
            } elseif ($newStatus === 1) {
                $detail = "Some required field for '$instrument' is not yet verified";
            } else {
                if (!$usingCustomLogic) {
                    $detail = "Not all required fields in '$instrument' are entered";
                } elseif ($logicCombination === 'none') {
                    $detail = "Custom logic for '$instrument' is not met";
                } else {
                    $detail = "Completion conditions (required fields " . strtoupper($logicCombination) . " custom logic) for '$instrument' are not met";
                }
            }

            REDCap::logEvent($this->getModuleName(), $detail, '', $record, $event_id);
        }

    }

    // The below code is an adaptation of DataEntry::checkReqFields
    // return 2 - Complete if all fields are entered
    // return 1 - Unverified - using data resolution workflow - when not verified/still an open query
    // return 0 - Incomplete if any field is missing a value
    private function checkRequiredFields($instrument, $checkVerifiedStatus, $record, $project_id, $event_id, $instance)
    {
        global $Proj;
        $emptyReqFields = $_POST['empty-required-field'] ?? [];
        $fieldsToCheckForVerification = [];

        foreach ($Proj->forms[$instrument]['fields'] as $field => $label) {
            if (!$this->isRequiredField($field, $emptyReqFields, $Proj)) {
                continue;
            }
            if ($this->isFieldMissing($field, $Proj)) {
                return 0; // Incomplete
            }

            // field is required and not missing
            if ($checkVerifiedStatus) {
                $fieldsToCheckForVerification[] = $field;
            }
        }

        if ($checkVerifiedStatus && !empty($fieldsToCheckForVerification)) {
            if (!$this->isAllFieldVerified($record, $project_id, $event_id, $fieldsToCheckForVerification, $instance)) {
                return 1; // Fields not verified
            }
        }

        return 2; // Complete
    }

    // new in version 2.0
    // Resolves the module behaviour for the given instrument from the repeatable
    // 'custom_logic_settings' group, which also determines which instruments
    // the module applies to. Returns [applies(bool), logic(string), combination(string)].
    //
    // Rules:
    // - No entries configured at all: fall back to the version 1.x instrument list
    //   (see getLegacyInstruments), and if that is empty too, apply to ALL instruments.
    // - Entry instrument lists are inclusive: an entry can name multiple instruments.
    // - An entry with NO instruments and the 'default' checkbox ticked acts as the
    //   catch-all for all instruments without their own entry. An exact instrument
    //   match always takes precedence over the catch-all, regardless of entry order.
    // - The checkbox is ignored (and hidden in the config UI) when instruments are
    //   selected, so a stale ticked value cannot silently turn an entry into a catch-all.
    // - An entry with blank logic applies the required fields check only.
    // - If entries exist and none covers this instrument, the module does not apply.
    // - Entries with no instruments and the checkbox unticked cover nothing and are
    //   ignored (this includes empty placeholder rows).
    private function getInstrumentSettings($instrument, $project_id = null)
    {
        // The project id is passed explicitly: on framework version 12 getSubSettings()
        // resolves it via requireProjectId(), so relying on the global project context
        // would be fragile.
        $entries = $this->getSubSettings('custom_logic_settings', $project_id);

        $configured = [];
        foreach ($entries as $entry) {
            $instruments = array_values(array_filter((array)($entry['custom_logic_instrument'] ?? [])));
            // Catch-all only when no instruments are selected; the checkbox is hidden
            // in the UI (and therefore ignored here) once instruments are chosen
            $isDefault = empty($instruments) && !empty($entry['custom_logic_apply_to_all']);

            if (empty($instruments) && !$isDefault) {
                continue; // covers no instruments
            }

            $configured[] = [
                'instruments' => $instruments,
                'is_default' => $isDefault,
                'logic' => trim($entry['custom_logic'] ?? ''),
                'combination' => ($entry['custom_logic_combination'] ?? '') ?: 'and'
            ];
        }

        // Nothing configured in the version 2.0 entries. Fall back to the version 1.x
        // instrument list so projects upgraded from 1.x keep the scope they had before
        // the upgrade; without this the module would silently start writing statuses to
        // every instrument in the project. An empty legacy list means "all instruments",
        // which is also the correct behaviour for a project that never ran 1.x.
        if (empty($configured)) {
            $legacyInstruments = $this->getLegacyInstruments($project_id);
            if (!empty($legacyInstruments)) {
                return [in_array($instrument, $legacyInstruments), '', 'and'];
            }

            return [true, '', 'and'];
        }

        $default = null;
        foreach ($configured as $entry) {
            if (in_array($instrument, $entry['instruments'])) {
                return [true, $entry['logic'], $entry['combination']];
            }
            if ($entry['is_default'] && $default === null) {
                $default = [true, $entry['logic'], $entry['combination']];
            }
        }

        return $default ?? [false, '', 'and'];
    }

    // Backward compatibility with version 1.x.
    // 'instruments_to_be_checked' was the version 1.x setting that limited the module to
    // specific instruments. It was replaced by 'custom_logic_settings' in version 2.0 and
    // is no longer declared in config.json, but the framework only ever writes settings
    // that were submitted by the config form, so the stored value survives the upgrade
    // untouched and can still be read here.
    //
    // Returns the configured instrument names with the empty placeholder entries removed.
    // An empty array means the project either never used the setting or left it blank,
    // both of which meant "all instruments" in version 1.x.
    // Protected rather than private so the unit tests can stub the stored setting.
    protected function getLegacyInstruments($project_id = null)
    {
        $legacyInstruments = $this->getProjectSetting('instruments_to_be_checked', $project_id);

        return array_values(array_filter(
            (array)$legacyInstruments,
            function ($instrument) {
                return $instrument !== null && $instrument !== '';
            }
        ));
    }

    // new in version 2.0
    // Evaluates the user-defined custom logic against the record's saved data.
    // Runs inside redcap_save_record, so the just-submitted values are already saved
    // and visible to REDCap::evaluateLogic().
    // Returns true/false, or null if the logic is invalid (which is logged).
    private function evaluateCustomLogic($logic, $project_id, $record, $event_id, $instrument, $instance)
    {
        global $Proj;

        $repeatInstrument = $Proj->isRepeatingForm($event_id, $instrument) ? $instrument : "";
        $result = REDCap::evaluateLogic($logic, $project_id, $record, $event_id, $instance, $repeatInstrument, $instrument);

        if ($result === null) {
            $message = "The custom logic configured for the " . $this->getModuleName()
                . " module could not be evaluated (invalid syntax?): " . $logic;
            error_log($message);
            REDCap::logEvent($this->getModuleName(), $message, '', $record, $event_id);
            return null;
        }

        return $result === true;
    }

    // new in version 3.0
    // Combines the required-fields status (0=Incomplete, 1=Unverified, 2=Complete)
    // with the custom logic result:
    //   AND:  custom logic false => Incomplete; true => required-fields result (preserves Unverified)
    //   OR:   custom logic true  => Complete;   false => required-fields result
    //   NONE: custom logic alone decides (normally handled before the required
    //         fields check is even run, but covered here for safety)
    private function combineWithCustomLogic($requiredFieldsStatus, $customLogicResult, $combination)
    {
        if ($combination === 'none') {
            return $customLogicResult ? 2 : 0;
        }

        if ($combination === 'or') {
            return $customLogicResult ? 2 : $requiredFieldsStatus;
        }

        // default: 'and'
        return $customLogicResult ? $requiredFieldsStatus : 0;
    }

    private function isRequiredField($field, $emptyReqFields, $Proj)
    {
        $isRequired = $Proj->metadata[$field]['field_req'] ?? false;
        $isNotEmptyRequired = !in_array($field, $emptyReqFields);

        if (!$isRequired || !$isNotEmptyRequired) {
            return false;
        }

        // Check for HIDDEN and HIDDEN-FORM tags (but not HIDDEN-SURVEY)
        $actionTags = str_replace(["\r", "\n", "\t"], " ", $Proj->metadata[$field]['misc'] ?? '');
        $actionTagsArr = explode(" ", $actionTags);

        return !array_intersect($actionTagsArr, ['@HIDDEN', '@HIDDEN-FORM']);
    }


    private function isAllFieldVerified($record, $project_id, $event_id, $field_names, $instance)
    {
        if (empty($field_names)) {
            return false;
        }

        // Create placeholders for each field name
        $placeholders = implode(',', array_fill(0, count($field_names), '?'));

        $params = array_merge(
            [$project_id, $record, $event_id, $instance],
            $field_names
        );

        $sql = "
            SELECT COUNT(DISTINCT field_name) as verified_count
            FROM redcap_data_quality_status
            WHERE project_id = ?
              AND record = ?
              AND event_id = ?
              AND instance = ?
              AND field_name IN ($placeholders)
              AND query_status IN ('CLOSED', 'VERIFIED')
        ";

        $result = $this->query($sql, $params);
        $row = $result->fetch_assoc();

        // Check if all fields were verified
        return $row['verified_count'] == count($field_names);
    }

    private function isFieldMissing($field, $Proj)
    {
        // Non-checkbox field. A field that was not posted at all (so not isset) is left
        // alone rather than treated as missing, matching version 1.x and REDCap's own
        // DataEntry::checkReqFields.
        if (!$Proj->isCheckbox($field)) {
            return isset($_POST[$field]) && $_POST[$field] === '';
        }

        // Checkbox field
        if (!isset($_POST["__chkn__{$field}"])) {
            $enum = parseEnum($Proj->metadata[$field]['element_enum']);
            foreach (array_keys($enum) as $key) {
                $checkboxField = "__chk__{$field}_RC_" . str_replace("|", ".", $key);
                // A ticked box posts its choice code and an unticked box posts ''. Compare
                // against '' rather than using empty(), otherwise a ticked box whose code
                // is '0' reads as unticked and the form is wrongly marked Incomplete.
                if (isset($_POST[$checkboxField]) && $_POST[$checkboxField] !== '') {
                    return false; // At least one checkbox is selected
                }
            }
            return true; // All checkboxes are empty
        }
        return false;
    }

    function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        $settings = ExternalModules::getProjectSettingsAsArray($this->PREFIX, $project_id);

        $statusFieldAction = $settings['status_field_action']['value'] ?? '';

        // Exit early if the module does not apply to this instrument
        list($applies) = $this->getInstrumentSettings($instrument, $project_id);
        if (!$applies) {
            return;
        }

        $jsInstrument = json_encode($instrument); // safely encode for JS

        switch ($statusFieldAction) {
            case 'disable':
                echo "<script>
                $(document).ready(function() {
                    $('[name=' + $jsInstrument + '_complete]').prop('disabled', true);
                });
            </script>";
                break;

            case 'as':
                // Show as it is - do nothing
                break;

            case 'hide':
            default:
                // 'hide' is the default when the setting has not been configured
                echo "<script>
                $(document).ready(function() {
                    $('#' + $jsInstrument + '_complete-sh-tr').hide();
                    $('#' + $jsInstrument + '_complete-tr').hide();
                });
            </script>";
                break;
        }
    }
}