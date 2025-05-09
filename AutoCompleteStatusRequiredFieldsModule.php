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
        $instrumentsToBeChecked = $settings['instruments_to_be_checked']['value'];
        // get the current value of 'complete'

        $currentCompleteStatusValue = $_POST[$instrument . '_complete'];

        // do not do the below if it is a delete action, ie $currentCompleteStatusValue is not set
        if (isset($currentCompleteStatusValue) && (is_null($instrumentsToBeChecked[0]) || in_array($instrument, $instrumentsToBeChecked))) {
            $newStatus = $this->checkRequiredFields($instrument);

            // if it is the same, do not update it
            if ($newStatus == $currentCompleteStatusValue) {
                return;
            }

            // if it is different name
            $redcapRecordIdFieldName = REDCap::getRecordIdField();
            $redcapEventName = REDCap::getEventNames(true, true, $event_id);

            global $Proj;

            $arrVarNames = array_merge(
                array($redcapRecordIdFieldName => $record,
                    'redcap_event_name' => $redcapEventName,
                    $instrument . '_complete' => $newStatus
                )
            );

            if ($Proj->isRepeatingForm($event_id, $instrument)) {
                $arrVarNames = array_merge($arrVarNames,
                    array(
                        'redcap_repeat_instrument' => $instrument,
                        'redcap_repeat_instance' => $repeat_instance
                    )
                );
            }
            else if ($Proj->isRepeatingEvent($event_id)) {
                $arrVarNames = array_merge($arrVarNames,
                    array(
                        'redcap_repeat_instance' => $repeat_instance
                    )
                );
            }

            $jsonData = json_encode(array($arrVarNames));
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
    }

    // The below code is an adaptation of DataEntry::checkReqFields
    // return 2 - Complete if all fields are entered
    // return 0 - Incomplete if any field is missing a value
    private function checkRequiredFields($instrument)
    {
        global $Proj;

        // Loop through each to check if required
        foreach ($Proj->forms[$instrument]['fields'] as $this_field=>$this_label)
        {
            // this is for PHP 8 fixes
            $emptyReqFields = array();
            if (isset($_POST['empty-required-field'])) {
                $emptyReqFields = $_POST['empty-required-field'];
            }
            // Only check field's value if the field is required and not part of hidden required fields
            if ($Proj->metadata[$this_field]['field_req'] && !in_array($this_field, $emptyReqFields))
            {
                // skip the @HIDDEN tags except the @HIDDEN-SURVEY tag
                $actionTags = str_replace(array("\r", "\n", "\t"), array(" ", " ", " "), $Proj->metadata[$this_field]['misc']);
                $actionTagsArr = explode(" ", $actionTags);
                if (array_intersect($actionTagsArr, ['@HIDDEN', '@HIDDEN-FORM'])) {
                    continue;
                }

                // Init the missing flag
                $isMissing = false;

                // Do check for non-checkbox fields
                if (isset($_POST[$this_field]) && !$Proj->isCheckbox($this_field) && $_POST[$this_field] == '')
                {
                    $isMissing = true;
                }
                // Do check for checkboxes, making sure at least one checkbox is checked
                elseif ($Proj->isCheckbox($this_field) && !isset($_POST["__chkn__".$this_field]))
                {
                    $checkboxEnum = parseEnum($Proj->metadata[$this_field]['element_enum']);
                    foreach (array_keys($checkboxEnum) as $key) {
                        $checkboxFieldName = "__chk__" . $this_field . "_RC_" . str_replace("|", ".", $key);
                        if (isset($_POST[$checkboxFieldName]) && $_POST[$checkboxFieldName] == '') {
                            // one of the checkbox is empty
                            // we only say is missing if all are empty
                            $isMissing = true;
                        } else {
                            // if any one of them has value, we set them not missing and exit the loop
                            $isMissing = false;
                            break;
                        }
                    }
                }

                if ($isMissing)
                {
                    return 0; // form status field = 'Incomplete'
                }
            }
        }
        return 2; // form status field = 'Complete'
    }

    function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        $settings = ExternalModules::getProjectSettingsAsArray($this->PREFIX, $project_id);
        $statusFieldAction = $settings['status_field_action']['value'];

        $instrumentsToBeChecked = $settings['instruments_to_be_checked']['value'];

        if (is_null($instrumentsToBeChecked[0]) || in_array($instrument, $instrumentsToBeChecked)) {
            if ($statusFieldAction == "as")
            {
                // do nothing
            }
            else if ($statusFieldAction == "disable")
            {
                echo '<script>$(document).ready(function() { $("[name=\'' . $instrument . '_complete\'").prop("disabled", "disabled"); });</script>';
            }
            else { // hidden - making it as the default for backward compatibility
                echo '<script>$(document).ready(function() { $("#' . $instrument . '_complete-sh-tr").hide(); $("#' . $instrument . '_complete-tr").hide(); });</script>';
            }
        }
    }

}