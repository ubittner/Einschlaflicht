<?php

/**
 * @project       Einschlaflicht/Einschlaflicht
 * @file          module.php
 * @author        Ulrich Bittner
 * @copyright     2023 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUnused */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class Einschlaflicht extends IPSModule
{
    //Helper
    use Control;
    use WeeklySchedule;

    //Constants
    private const MODULE_PREFIX = 'ESL';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        ##### Properties

        //Device
        $this->RegisterPropertyInteger('DevicePower', 0);
        $this->RegisterPropertyInteger('DeviceBrightness', 0);
        $this->RegisterPropertyInteger('DeviceColor', 0);

        //Weekly schedule
        $this->RegisterPropertyInteger('WeeklySchedule', 0);

        //Checks
        $this->RegisterPropertyBoolean('CheckDevicePower', true);
        $this->RegisterPropertyBoolean('CheckDeviceBrightness', true);

        ##### Variables

        //Sleep light
        $id = @$this->GetIDForIdent('SleepLight');
        $this->RegisterVariableBoolean('SleepLight', 'Einschlaflicht', '~Switch', 10);
        $this->EnableAction('SleepLight');
        if (!$id) {
            IPS_SetIcon(@$this->GetIDForIdent('SleepLight'), 'Moon');
        }

        //Brightness
        $id = @$this->GetIDForIdent('Brightness');
        $this->RegisterVariableInteger('Brightness', 'Helligkeit', '~Intensity.100', 20);
        $this->EnableAction('Brightness');
        if (!$id) {
            $this->SetValue('Brightness', 50);
        }

        //ColorSelection
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.ColorSelection';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileIcon($profile, 'Menu');
        IPS_SetVariableProfileValues($profile, 0, 1, 0);
        IPS_SetVariableProfileDigits($profile, 0);
        IPS_SetVariableProfileAssociation($profile, 0, 'Zuletzt verwendet', '', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 1, 'Benutzerdefiniert', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 52224, 'Grün', '', 52224);
        IPS_SetVariableProfileAssociation($profile, 874662, 'Blau', '', 874662);
        IPS_SetVariableProfileAssociation($profile, 4657582, 'Violett', '', 4657582);
        IPS_SetVariableProfileAssociation($profile, 12984992, 'Magenta', '', 12984992);
        IPS_SetVariableProfileAssociation($profile, 16750848, 'Orange', '', 16750848);
        $id = @$this->GetIDForIdent('ColorSelection');
        $this->RegisterVariableInteger('ColorSelection', 'Farbauswahl', $profile, 30);
        $this->EnableAction('ColorSelection');
        if (!$id) {
            $this->SetValue('ColorSelection', 0);
        }

        //Color
        $id = @$this->GetIDForIdent('Color');
        $this->RegisterVariableInteger('Color', 'Farbe', '~HexColor', 40);
        $this->EnableAction('Color');
        if (!$id) {
            $this->SetValue('Color', 16750848);
            IPS_SetIcon(@$this->GetIDForIdent('Color'), 'Paintbrush');
        }

        //Duration
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.Duration';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileIcon($profile, 'Hourglass');
        IPS_SetVariableProfileValues($profile, 15, 120, 0);
        IPS_SetVariableProfileDigits($profile, 0);
        IPS_SetVariableProfileAssociation($profile, 15, '15 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 30, '30 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 45, '45 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 60, '60 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 90, '90 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 120, '120 Min.', '', 0x0000FF);
        $id = @$this->GetIDForIdent('Duration');
        $this->RegisterVariableInteger('Duration', 'Dauer', $profile, 50);
        $this->EnableAction('Duration');
        if (!$id) {
            $this->SetValue('Duration', 30);
        }

        //Process finished
        $id = @$this->GetIDForIdent('ProcessFinished');
        $this->RegisterVariableString('ProcessFinished', 'Schaltvorgang bis', '', 70);
        if (!$id) {
            IPS_SetIcon($this->GetIDForIdent('ProcessFinished'), 'Clock');
        }

        ##### Attributes

        $this->RegisterAttributeInteger('CyclingBrightness', 0);
        $this->RegisterAttributeInteger('EndTime', 0);

        #### Timer

        $this->RegisterTimer('DecreaseBrightness', 0, self::MODULE_PREFIX . '_DecreaseBrightness(' . $this->InstanceID . ');');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();

        //Delete profiles
        $profiles = ['ColorSelection', 'Duration'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = self::MODULE_PREFIX . '.' . $this->InstanceID . '.' . $profile;
                $this->UnregisterProfile($profileName);
            }
        }
    }

    public function ApplyChanges()
    {
        //Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        //Never delete this line!
        parent::ApplyChanges();

        //Check kernel runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        //Delete all references
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        //Delete all messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message == VM_UPDATE || $message == EM_UPDATE) {
                    $this->UnregisterMessage($senderID, $message);
                }
            }
        }

        //Register references and messages
        $names = [];
        $names[] = ['propertyName' => 'DevicePower', 'messageCategory' => VM_UPDATE];
        $names[] = ['propertyName' => 'DeviceBrightness', 'messageCategory' => VM_UPDATE];
        $names[] = ['propertyName' => 'DeviceColor', 'messageCategory' => 0];
        $names[] = ['propertyName' => 'WeeklySchedule', 'messageCategory' => EM_UPDATE];
        foreach ($names as $name) {
            $id = $this->ReadPropertyInteger($name['propertyName']);
            if ($id > 1 && @IPS_ObjectExists($id)) { //0 = main category, 1 = none
                $this->RegisterReference($id);
                if ($name['messageCategory'] != 0) {
                    $this->RegisterMessage($id, $name['messageCategory']);
                }
            }
        }

        //Disable color
        $disabled = true;
        if ($this->GetValue('ColorSelection') == 1) {
            $disabled = false;
        }
        IPS_SetDisabled($this->GetIDForIdent('Color'), $disabled);

        //Hide process finished
        if (!$this->GetValue('SleepLight')) {
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), true);
        }

        //Check weekly schedule
        if (!$this->ValidateWeeklySchedule()) {
            $this->DeleteWeeklySchedule();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

            case VM_UPDATE:

                //$Data[0] = actual value
                //$Data[1] = value changed
                //$Data[2] = last value
                //$Data[3] = timestamp actual value
                //$Data[4] = timestamp value changed
                //$Data[5] = timestamp last value

                //Device power
                $devicePowerID = $this->ReadPropertyInteger('DevicePower');
                if ($SenderID == $devicePowerID) {
                    if ($this->ReadPropertyBoolean('CheckDevicePower')) {
                        //Device is powered off
                        if ($this->GetValue('SleepLight') && !GetValue($devicePowerID)) {
                            $this->SendDebug(__FUNCTION__, 'Abbruch, Lampe wurde ausgeschaltet!', 0);
                            $this->ToggleSleepLight(false);
                        }
                    }
                }

                //Device Brightness
                $deviceBrightnessID = $this->ReadPropertyInteger('DeviceBrightness');
                if ($SenderID == $deviceBrightnessID) {
                    if ($this->GetValue('SleepLight')) {
                        $this->SendDebug(__FUNCTION__, 'Lampe-Helligkeit: ' . $Data[0], 0);
                        $deviceBrightness = GetValue($deviceBrightnessID);
                        $cyclingBrightness = $this->ReadAttributeInteger('CyclingBrightness');
                        if ($this->ReadPropertyBoolean('CheckDeviceBrightness')) {
                            if ($deviceBrightness != $cyclingBrightness) {
                                $this->SendDebug(__FUNCTION__, 'Abbruch, Lampe-Helligkeit wurde manuell geändert!', 0);
                                $this->ToggleSleepLight(false);
                            }
                        }
                    }
                }
                break;

            case EM_UPDATE:

                //$Data[0] = last run
                //$Data[1] = next run

                //Weekly schedule
                if ($this->ValidateWeeklySchedule()) {
                    if ($this->DetermineAction() == 1) {
                        $this->ToggleSleepLight(true);
                    }
                }
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        ##### Elements

        //Device power
        $devicePowerID = $this->ReadPropertyInteger('DevicePower');
        $enableButton = false;
        if ($devicePowerID > 1 && @IPS_ObjectExists($devicePowerID)) { //0 = main category, 1 = none
            $enableButton = true;
        }
        $data['elements'][0]['items'][1] = [
            'type'     => 'OpenObjectButton',
            'caption'  => 'ID ' . $devicePowerID . ' bearbeiten',
            'name'     => 'DevicePowerConfigurationButton',
            'visible'  => $enableButton,
            'objectID' => $devicePowerID
        ];

        //Device Brightness
        $deviceBrightnessID = $this->ReadPropertyInteger('DeviceBrightness');
        $enableButton = false;
        if ($deviceBrightnessID > 1 && @IPS_ObjectExists($deviceBrightnessID)) { //0 = main category, 1 = none
            $enableButton = true;
        }
        $data['elements'][1]['items'][1] = [
            'type'     => 'OpenObjectButton',
            'caption'  => 'ID ' . $deviceBrightnessID . ' bearbeiten',
            'name'     => 'DeviceBrightnessConfigurationButton',
            'visible'  => $enableButton,
            'objectID' => $deviceBrightnessID
        ];

        //Device color
        $deviceColorID = $this->ReadPropertyInteger('DeviceColor');
        $enableButton = false;
        if ($deviceColorID > 1 && @IPS_ObjectExists($deviceColorID)) { //0 = main category, 1 = none
            $enableButton = true;
        }
        $data['elements'][2]['items'][1] = [
            'type'     => 'OpenObjectButton',
            'caption'  => 'ID ' . $deviceColorID . ' bearbeiten',
            'name'     => 'DeviceColorConfigurationButton',
            'visible'  => $enableButton,
            'objectID' => $deviceColorID
        ];

        //Weekly schedule
        $weeklyScheduleID = $this->ReadPropertyInteger('WeeklySchedule');
        $enableButton = false;
        if ($weeklyScheduleID > 1 && @IPS_ObjectExists($weeklyScheduleID)) { //0 = main category, 1 = none
            $enableButton = true;
        }
        $data['elements'][4]['items'][1] = [
            'type'     => 'OpenObjectButton',
            'caption'  => 'ID ' . $weeklyScheduleID . ' bearbeiten',
            'name'     => 'WeeklyScheduleConfigurationButton',
            'visible'  => $enableButton,
            'objectID' => $weeklyScheduleID

        ];

        //Create weekly schedule button
        $data['elements'][5] = [
            'type'    => 'PopupButton',
            'caption' => 'Wochenplan erstellen',
            'popup'   => [
                'caption' => 'Wochenplan wirklich erstellen und zuweisen?',
                'items'   => [
                    [
                        'type'  => 'RowLayout',
                        'items' => [
                            [
                                'type'  => 'CheckBox',
                                'name'  => 'UseMonday',
                                'value' => true
                            ],
                            [
                                'type'    => 'Label',
                                'caption' => "Montag\t\t"
                            ],
                            [
                                'type'    => 'SelectTime',
                                'name'    => 'MondayStartTime',
                                'caption' => 'Startzeit',
                                'width'   => '120px',
                                'value'   => '{"hour": "22", "minute": "30", "second": "00"}'
                            ]
                        ]
                    ],
                    [
                        'type'  => 'RowLayout',
                        'items' => [
                            [
                                'type'  => 'CheckBox',
                                'name'  => 'UseTuesday',
                                'value' => true
                            ],
                            [
                                'type'    => 'Label',
                                'caption' => "Dienstag\t"
                            ],
                            [
                                'type'    => 'SelectTime',
                                'name'    => 'TuesdayStartTime',
                                'caption' => 'Startzeit',
                                'width'   => '120px',
                                'value'   => '{"hour": "22", "minute": "30", "second": "00"}'
                            ]
                        ]
                    ],
                    [
                        'type'  => 'RowLayout',
                        'items' => [
                            [
                                'type'  => 'CheckBox',
                                'name'  => 'UseWednesday',
                                'value' => true
                            ],
                            [
                                'type'    => 'Label',
                                'caption' => "Mittwoch\t"
                            ],
                            [
                                'type'    => 'SelectTime',
                                'name'    => 'WednesdayStartTime',
                                'caption' => 'Startzeit',
                                'width'   => '120px',
                                'value'   => '{"hour": "22", "minute": "30", "second": "00"}'
                            ]
                        ]
                    ],
                    [
                        'type'  => 'RowLayout',
                        'items' => [
                            [
                                'type'  => 'CheckBox',
                                'name'  => 'UseThursday',
                                'value' => true
                            ],
                            [
                                'type'    => 'Label',
                                'caption' => "Donnerstag\t"
                            ],
                            [
                                'type'    => 'SelectTime',
                                'name'    => 'ThursdayStartTime',
                                'caption' => 'Startzeit',
                                'width'   => '120px',
                                'value'   => '{"hour": "22", "minute": "30", "second": "00"}'
                            ]
                        ]
                    ],
                    [
                        'type'  => 'RowLayout',
                        'items' => [
                            [
                                'type'  => 'CheckBox',
                                'name'  => 'UseFriday',
                                'value' => true
                            ],
                            [
                                'type'    => 'Label',
                                'caption' => "Freitag\t\t"
                            ],
                            [
                                'type'    => 'SelectTime',
                                'name'    => 'FridayStartTime',
                                'caption' => 'Startzeit',
                                'width'   => '120px',
                                'value'   => '{"hour": "23", "minute": "00", "second": "00"}'
                            ]
                        ]
                    ],
                    [
                        'type'  => 'RowLayout',
                        'items' => [
                            [
                                'type'  => 'CheckBox',
                                'name'  => 'UseSaturday',
                                'value' => true
                            ],
                            [
                                'type'    => 'Label',
                                'caption' => "Samstag\t"
                            ],
                            [
                                'type'    => 'SelectTime',
                                'name'    => 'SaturdayStartTime',
                                'caption' => 'Startzeit',
                                'width'   => '120px',
                                'value'   => '{"hour": "23", "minute": "30", "second": "00"}'
                            ]
                        ]
                    ],
                    [
                        'type'  => 'RowLayout',
                        'items' => [
                            [
                                'type'  => 'CheckBox',
                                'name'  => 'UseSunday',
                                'value' => true
                            ],
                            [
                                'type'    => 'Label',
                                'caption' => "Sonntag\t\t"
                            ],
                            [
                                'type'    => 'SelectTime',
                                'name'    => 'SundayStartTime',
                                'caption' => 'Startzeit',
                                'width'   => '120px',
                                'value'   => '{"hour": "22", "minute": "30", "second": "00"}'
                            ]
                        ]
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => 'Erstellen',
                        'onClick' => [
                            '$events["Monday"] = ["days" => 1, "use" => $UseMonday, "startTime" => $MondayStartTime];',
                            '$events["Tuesday"] = ["days" => 2, "use" => $UseTuesday, "startTime" => $TuesdayStartTime];',
                            '$events["Wednesday"] = ["days" => 4, "use" => $UseWednesday, "startTime" => $WednesdayStartTime];',
                            '$events["Thursday"] = ["days" => 8, "use" => $UseThursday, "startTime" => $ThursdayStartTime];',
                            '$events["Friday"] = ["days" => 16, "use" => $UseFriday, "startTime" => $FridayStartTime];',
                            '$events["Saturday"] = ["days" => 32, "use" => $UseSaturday, "startTime" => $SaturdayStartTime];',
                            '$events["Sunday"] = ["days" => 64, "use" => $UseSunday, "startTime" => $SundayStartTime];',
                            '$eventID = ESL_CreateWeeklySchedule($id, json_encode($events));'
                        ]
                    ]
                ]
            ]
        ];

        return json_encode($data);
    }

    /**
     * Modifies a configuration button.
     *
     * @param string $Field
     * @param string $Caption
     * @param int $ObjectID
     * @return void
     */
    public function ModifyButton(string $Field, string $Caption, int $ObjectID): void
    {
        $state = false;
        if ($ObjectID > 1 && @IPS_ObjectExists($ObjectID)) { //0 = main category, 1 = none
            $state = true;
        }
        $this->UpdateFormField($Field, 'caption', $Caption);
        $this->UpdateFormField($Field, 'visible', $state);
        $this->UpdateFormField($Field, 'objectID', $ObjectID);
    }

    #################### Request action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'SleepLight':
                $this->ToggleSleepLight($Value);
                break;

            case 'ColorSelection':
                $this->SetValue($Ident, $Value);
                $disabled = true;
                if ($Value == 1) {
                    $disabled = false;
                }
                IPS_SetDisabled($this->GetIDForIdent('Color'), $disabled);
                break;

            case 'Brightness':
            case 'Color':
            case 'Duration':
                $this->SetValue($Ident, $Value);
                break;

        }
    }

    #################### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    /**
     * Unregisters a variable profile.
     *
     * @param string $Name
     * @return void
     */
    private function UnregisterProfile(string $Name): void
    {
        if (!IPS_VariableProfileExists($Name)) {
            return;
        }
        foreach (IPS_GetVariableList() as $VarID) {
            if (IPS_GetParent($VarID) == $this->InstanceID) {
                continue;
            }
            if (IPS_GetVariable($VarID)['VariableCustomProfile'] == $Name) {
                return;
            }
            if (IPS_GetVariable($VarID)['VariableProfile'] == $Name) {
                return;
            }
        }
        foreach (IPS_GetMediaListByType(MEDIATYPE_CHART) as $mediaID) {
            $content = json_decode(base64_decode(IPS_GetMediaContent($mediaID)), true);
            foreach ($content['axes'] as $axis) {
                if ($axis['profile' === $Name]) {
                    return;
                }
            }
        }
        IPS_DeleteVariableProfile($Name);
    }
}