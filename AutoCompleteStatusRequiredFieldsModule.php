<?php

namespace Monash\Helix\AutoCompleteStatusRequiredFieldsModule;

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
        $instrumentsToBeChecked = $settings['instruments_to_be_checked']['value'] ?? [];
        $currentCompleteStatus = $_POST[$instrument . '_complete'] ?? null;

        // Skip if it's a delete or the instrument isn't in the list to check
        if (!isset($currentCompleteStatus) || (!is_null($instrumentsToBeChecked[0]) && !in_array($instrument, $instrumentsToBeChecked))) {
            return;
        }

        $newStatus = $this->checkRequiredFields($instrument);
        if ($newStatus === $currentCompleteStatus) {
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
            $detail = ($newStatus === 2)
                ? "All required fields in '$instrument' are entered"
                : "Not all required fields in '$instrument' are entered";

            REDCap::logEvent($this->getModuleName(), $detail, '', $record, $event_id);
        }

    }

    // The below code is an adaptation of DataEntry::checkReqFields
    // return 2 - Complete if all fields are entered
    // return 0 - Incomplete if any field is missing a value
    private function checkRequiredFields($instrument)
    {
        global $Proj;
        $emptyReqFields = $_POST['empty-required-field'] ?? [];

        foreach ($Proj->forms[$instrument]['fields'] as $field => $label) {
            if (!$this->isRequiredField($field, $emptyReqFields, $Proj)) {
                continue;
            }
            if ($this->isFieldMissing($field, $Proj)) {
                return 0; // Incomplete
            }
        }
        return 2; // Complete
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

    private function isFieldMissing($field, $Proj)
    {
        // Non-checkbox field
        if (!$Proj->isCheckbox($field)) {
            return $_POST[$field] === '';
            // the below statement will not work when there is a randomised field in the form because the randomised
            // field value will not be passed back when we save the form
            //return !isset($_POST[$field]) || $_POST[$field] === '';
        }

        // Checkbox field
        if (!isset($_POST["__chkn__{$field}"])) {
            $enum = parseEnum($Proj->metadata[$field]['element_enum']);
            foreach (array_keys($enum) as $key) {
                $checkboxField = "__chk__{$field}_RC_" . str_replace("|", ".", $key);
                if (!empty($_POST[$checkboxField])) {
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
        $instrumentsToBeChecked = $settings['instruments_to_be_checked']['value'] ?? [];

        // Exit early if instrument is not in the list to be checked
        if (!is_null($instrumentsToBeChecked[0]) && !in_array($instrument, $instrumentsToBeChecked)) {
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

            case 'hidden':
            default:
                echo "<script>
                $(document).ready(function() {
                    $('#' + $jsInstrument + '_complete-sh-tr').hide();
                    $('#' + $jsInstrument + '_complete-tr').hide();
                });
            </script>";
                break;

            case 'as':
                // Do nothing
                break;
        }
    }
}