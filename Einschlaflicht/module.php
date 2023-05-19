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

include_once __DIR__ . '/helper/ESL_autoload.php';

class Einschlaflicht extends IPSModule
{
    ##### Helper
    use ESL_Control;
    use ESL_WeeklySchedule;

    ##### Constants
    private const MODULE_NAME = 'Einschlaflicht';
    private const MODULE_PREFIX = 'ESL';
    private const MODULE_VERSION = '1.0-3, 19.05.2023';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        ##### Properties

        $this->RegisterPropertyInteger('LightStatus', 0);
        $this->RegisterPropertyInteger('LightBrightness', 0);
        $this->RegisterPropertyInteger('LightColor', 0);
        $this->RegisterPropertyInteger('WeeklySchedule', 0);
        $this->RegisterPropertyBoolean('UseWeekday', true);
        $this->RegisterPropertyString('WeekdayStartTime', '{"hour": "22", "minute": "30", "second": "0"}');
        $this->RegisterPropertyInteger('WeekdayDuration', 60);
        $this->RegisterPropertyInteger('WeekdayBrightness', 50);
        $this->RegisterPropertyInteger('WeekdayColor', 16750848);
        $this->RegisterPropertyBoolean('UseWeekend', true);
        $this->RegisterPropertyString('WeekendStartTime', '{"hour": "23", "minute": "30", "second": "0"}');
        $this->RegisterPropertyInteger('WeekendDuration', 30);
        $this->RegisterPropertyInteger('WeekendBrightness', 50);
        $this->RegisterPropertyInteger('WeekendColor', 16750848);

        ##### Variables

        //Sleep light
        $id = @$this->GetIDForIdent('SleepLight');
        $this->RegisterVariableBoolean('SleepLight', 'Einschlaflicht', '~Switch', 10);
        $this->EnableAction('SleepLight');
        if (!$id) {
            IPS_SetIcon(@$this->GetIDForIdent('SleepLight'), 'Bulb');
        }

        //Brightness
        $id = @$this->GetIDForIdent('Brightness');
        $this->RegisterVariableInteger('Brightness', 'Helligkeit', '~Intensity.100', 20);
        $this->EnableAction('Brightness');
        if (!$id) {
            $this->SetValue('Brightness', 50);
        }

        //Color
        $id = @$this->GetIDForIdent('Color');
        $this->RegisterVariableInteger('Color', 'Farbe', '~HexColor', 30);
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
        IPS_SetVariableProfileValues($profile, 0, 120, 0);
        IPS_SetVariableProfileDigits($profile, 0);
        IPS_SetVariableProfileAssociation($profile, 15, '15 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 30, '30 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 45, '45 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 60, '60 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 90, '90 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 120, '120 Min.', '', 0x0000FF);
        $id = @$this->GetIDForIdent('Duration');
        $this->RegisterVariableInteger('Duration', 'Dauer', $profile, 40);
        $this->EnableAction('Duration');
        if (!$id) {
            $this->SetValue('Duration', 30);
        }

        //Process finished
        $id = @$this->GetIDForIdent('ProcessFinished');
        $this->RegisterVariableString('ProcessFinished', 'Schaltvorgang bis', '', 60);
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
        $profiles = ['Duration'];
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
        $names[] = ['propertyName' => 'LightStatus', 'messageCategory' => VM_UPDATE];
        $names[] = ['propertyName' => 'LightBrightness', 'messageCategory' => VM_UPDATE];
        $names[] = ['propertyName' => 'LightColor', 'messageCategory' => 0];
        $names[] = ['propertyName' => 'WeeklySchedule', 'messageCategory' => EM_UPDATE];
        foreach ($names as $name) {
            $id = $this->ReadPropertyInteger($name['propertyName']);
            if ($id > 1 && @IPS_ObjectExists($id)) { //0 = main category, 1 = none
                $this->RegisterReference($id);
                $this->SendDebug('RegisterMessage', 'ID: ' . $id . ', Name: ' . $name['propertyName'] . ', Message: ' . $name['messageCategory'], 0);
                if ($name['messageCategory'] != 0) {
                    $this->SendDebug('RegisterMessage', ' wird ausgeführt', 0);
                    $this->SendDebug('RegisterMessage', 'ID: ' . $id . ', Message: ' . $name['messageCategory'], 0);
                    $this->RegisterMessage($id, $name['messageCategory']);
                }
            }
        }

        if (!$this->GetValue('SleepLight')) {
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), true);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug('MessageSink', 'SenderID: ' . $SenderID . ', Message: ' . $Message, 0);
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

                $lightStatus = $this->ReadPropertyInteger('LightStatus');
                if ($SenderID == $lightStatus) {
                    if (!GetValue($lightStatus)) {
                        $this->ToggleSleepLight(false);
                    }
                }

                //Light brightness
                $lightBrightness = $this->ReadPropertyInteger('LightBrightness');
                if ($SenderID == $lightBrightness) {
                    if ($this->GetValue('SleepLight')) {
                        if (GetValue($lightBrightness) > $this->ReadAttributeInteger('CyclingBrightness') + 1) {
                            $this->ToggleSleepLight(false);
                        }
                    }
                }
                break;

            case EM_UPDATE:

                //$Data[0] = last run
                //$Data[1] = next run

                //Weekly schedule
                if ($this->DetermineAction() == 1) {
                    $this->ToggleSleepLight(true, 1);
                }
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        ##### Elements
        //Module name
        $data['elements'][0]['caption'] = self::MODULE_NAME;

        //Version
        $data['elements'][1]['caption'] = 'Version: ' . self::MODULE_VERSION;

        //Weekly schedule
        $id = $this->ReadPropertyInteger('WeeklySchedule');
        $enableButton = false;
        if ($id > 1 && @IPS_ObjectExists($id)) { //0 = main category, 1 = none
            $enableButton = true;
        }

        $data['elements'][4]['items'][0] = [
            'type'  => 'RowLayout',
            'items' => [
                [
                    'type'     => 'SelectEvent',
                    'name'     => 'WeeklySchedule',
                    'caption'  => 'Wochenplan',
                    'width'    => '600px',
                    'onChange' => self::MODULE_PREFIX . '_ModifyButton($id, "WeeklyScheduleConfigurationButton", "ID " . $WeeklySchedule . " bearbeiten", $WeeklySchedule);'
                ],
                [
                    'type'    => 'Label',
                    'caption' => ' '
                ],
                [
                    'type'     => 'OpenObjectButton',
                    'caption'  => 'ID ' . $id . ' bearbeiten',
                    'name'     => 'WeeklyScheduleConfigurationButton',
                    'visible'  => $enableButton,
                    'objectID' => $id
                ]
            ]
        ];

        ##### Actions

        //Registered messages
        $registeredMessages = [];
        $messages = $this->GetMessageList();
        foreach ($messages as $id => $messageID) {
            $name = 'Objekt #' . $id . ' existiert nicht';
            $rowColor = '#FFC0C0'; //red
            if (@IPS_ObjectExists($id)) {
                $name = IPS_GetName($id);
                $rowColor = '#C0FFC0'; //light green
            }
            switch ($messageID) {
                case [10001]:
                    $messageDescription = 'IPS_KERNELSTARTED';
                    break;

                case [10603]:
                    $messageDescription = 'VM_UPDATE';
                    break;

                case [10803]:
                    $messageDescription = 'EM_UPDATE';
                    break;

                default:
                    $messageDescription = 'keine Bezeichnung';
            }
            $registeredMessages[] = [
                'ObjectID'           => $id,
                'Name'               => $name,
                'MessageID'          => $messageID,
                'MessageDescription' => $messageDescription,
                'rowColor'           => $rowColor];
        }

        $data['actions'][1] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Registrierte Nachrichten',
            'items'   => [
                [
                    'type'     => 'List',
                    'name'     => 'RegisteredMessages',
                    'rowCount' => 10,
                    'sort'     => [
                        'column'    => 'ObjectID',
                        'direction' => 'ascending'
                    ],
                    'columns' => [
                        [
                            'caption' => 'ID',
                            'name'    => 'ObjectID',
                            'width'   => '150px',
                            'onClick' => self::MODULE_PREFIX . '_ModifyButton($id, "RegisteredMessagesConfigurationButton", "ID " . $RegisteredMessages["ObjectID"] . " aufrufen", $RegisteredMessages["ObjectID"]);'
                        ],
                        [
                            'caption' => 'Name',
                            'name'    => 'Name',
                            'width'   => '300px',
                            'onClick' => self::MODULE_PREFIX . '_ModifyButton($id, "RegisteredMessagesConfigurationButton", "ID " . $RegisteredMessages["ObjectID"] . " aufrufen", $RegisteredMessages["ObjectID"]);'
                        ],
                        [
                            'caption' => 'Nachrichten ID',
                            'name'    => 'MessageID',
                            'width'   => '150px'
                        ],
                        [
                            'caption' => 'Nachrichten Bezeichnung',
                            'name'    => 'MessageDescription',
                            'width'   => '250px'
                        ]
                    ],
                    'values' => $registeredMessages
                ],
                [
                    'type'     => 'OpenObjectButton',
                    'name'     => 'RegisteredMessagesConfigurationButton',
                    'caption'  => 'Aufrufen',
                    'visible'  => false,
                    'objectID' => 0
                ]
            ]
        ];

        //Registered references
        $registeredReferences = [];
        $references = $this->GetReferenceList();
        foreach ($references as $reference) {
            $name = 'Objekt #' . $reference . ' existiert nicht';
            $rowColor = '#FFC0C0'; //red
            if (@IPS_ObjectExists($reference)) {
                $name = IPS_GetName($reference);
                $rowColor = '#C0FFC0'; //light green
            }
            $registeredReferences[] = [
                'ObjectID' => $reference,
                'Name'     => $name,
                'rowColor' => $rowColor];
        }

        $data['actions'][2] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Registrierte Referenzen',
            'items'   => [
                [
                    'type'     => 'List',
                    'name'     => 'RegisteredReferences',
                    'rowCount' => 10,
                    'sort'     => [
                        'column'    => 'ObjectID',
                        'direction' => 'ascending'
                    ],
                    'columns' => [
                        [
                            'caption' => 'ID',
                            'name'    => 'ObjectID',
                            'width'   => '150px',
                            'onClick' => self::MODULE_PREFIX . '_ModifyButton($id, "RegisteredReferencesConfigurationButton", "ID " . $RegisteredReferences["ObjectID"] . " aufrufen", $RegisteredReferences["ObjectID"]);'
                        ],
                        [
                            'caption' => 'Name',
                            'name'    => 'Name',
                            'width'   => '300px',
                            'onClick' => self::MODULE_PREFIX . '_ModifyButton($id, "RegisteredReferencesConfigurationButton", "ID " . $RegisteredReferences["ObjectID"] . " aufrufen", $RegisteredReferences["ObjectID"]);'
                        ]
                    ],
                    'values' => $registeredReferences
                ],
                [
                    'type'     => 'OpenObjectButton',
                    'name'     => 'RegisteredReferencesConfigurationButton',
                    'caption'  => 'Aufrufen',
                    'visible'  => false,
                    'objectID' => 0
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

            case 'Color':
            case 'Brightness':
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