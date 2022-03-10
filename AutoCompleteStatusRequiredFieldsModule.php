<?php

namespace Monash\Helix\AutoCompleteStatusRequiredFieldsModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

class AutoCompleteStatusRequiredFieldsModule extends AbstractExternalModule
{
    public function __construct() {
        parent::__construct();
    }

    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $settings = ExternalModules::getProjectSettingsAsArray($this->PREFIX, $project_id);
        $instrumentsToBeChecked = $settings['instruments_to_be_checked']['value'];
        // get the current value of 'complete'
        $currentCompleteStatusValue = $_POST[$instrument . '_complete'];

        // do not do the below if it is a delete action, ie $currentCompleteStatusValue is not set
        if (isset($currentCompleteStatusValue) && is_null($instrumentsToBeChecked[0]) || in_array($instrument, $instrumentsToBeChecked)) {
            $isAllRequiredFieldsEntered = $this->checkRequiredFields($instrument);

            // if it is the same, do not update it
            if ($isAllRequiredFieldsEntered == $currentCompleteStatusValue) {
                return;
            }

            // if it is different name
            $redcapRecordIdFieldName = REDCap::getRecordIdField();
            $redcapEventName = REDCap::getEventNames(true, true, $event_id);

            global $Proj;

            $arrVarNames = array_merge(
                array($redcapRecordIdFieldName => $record,
                    'redcap_event_name' => $redcapEventName,
                    $instrument . '_complete' => $isAllRequiredFieldsEntered
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

            if (count($saveResponse['errors'])>0) {
                $errors = $saveResponse['errors'];

                $errorString = stripslashes(json_encode($errors, JSON_PRETTY_PRINT));
                $errorString = str_replace('""', '"', $errorString);

                $message = "The " . $this->getModuleName() . " could not save $instrument form status because of the following error(s):\n\n$errorString";
                error_log($message);
            } else {
                // save to REDCap log
                if ($isAllRequiredFieldsEntered === 2)
                {
                    $detail = "All required fields at '$instrument' are entered";
                }
                else
                {
                    $detail = "Not all required fields at '$instrument' are entered";
                }
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
            // Only check field's value if the field is required and not hidden
            if ($Proj->metadata[$this_field]['field_req'] && !in_array($this_field, $_POST['empty-required-field']))
            {
                // skip only @HIDDEN tag - as Form Status field is not in a survey so there is no need to skip @HIDDEN-SURVEY
                $actionTags = str_replace(array("\r", "\n", "\t"), array(" ", " ", " "), $Proj->metadata[$this_field]['misc']);
                $actionTagsArr = explode(" ", $actionTags);
                if (in_array('@HIDDEN', $actionTagsArr)) {
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