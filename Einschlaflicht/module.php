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

    ##### Constants
    private const MODULE_NAME = 'Einschlaflicht';
    private const MODULE_PREFIX = 'ESL';
    private const MODULE_VERSION = '1.0-2, 07.05.2023';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        ##### Properties

        $this->RegisterPropertyInteger('LightStatus', 0);
        $this->RegisterPropertyInteger('LightColor', 0);
        $this->RegisterPropertyInteger('LightBrightness', 0);

        ##### Variables

        //Sleep light
        $id = @$this->GetIDForIdent('SleepLight');
        $this->RegisterVariableBoolean('SleepLight', 'Einschlaflicht', '~Switch', 10);
        $this->EnableAction('SleepLight');
        if (!$id) {
            IPS_SetIcon(@$this->GetIDForIdent('SleepLight'), 'Bulb');
        }

        //Color
        $id = @$this->GetIDForIdent('Color');
        $this->RegisterVariableInteger('Color', 'Farbe', '~HexColor', 20);
        $this->EnableAction('Color');
        if (!$id) {
            $this->SetValue('Color', 16750848);
            IPS_SetIcon(@$this->GetIDForIdent('Color'), 'Paintbrush');
        }

        //Brightness
        $id = @$this->GetIDForIdent('Brightness');
        $this->RegisterVariableInteger('Brightness', 'Helligkeit', '~Intensity.100', 30);
        $this->EnableAction('Brightness');
        if (!$id) {
            $this->SetValue('Brightness', 50);
        }

        //Duration
        $profile = self::MODULE_PREFIX . '.' . $this->InstanceID . '.Duration';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileIcon($profile, 'Hourglass');
        IPS_SetVariableProfileValues($profile, 0, 120, 0);
        IPS_SetVariableProfileDigits($profile, 0);
        IPS_SetVariableProfileAssociation($profile, 3, '3 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 5, '5 Min.', '', 0x0000FF);
        IPS_SetVariableProfileAssociation($profile, 10, '10 Min.', '', 0x0000FF);
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

        //Next power off
        $id = @$this->GetIDForIdent('NextPowerOff');
        $this->RegisterVariableString('NextPowerOff', 'Nächste Ausschaltung', '', 50);
        if (!$id) {
            IPS_SetIcon($this->GetIDForIdent('NextPowerOff'), 'Clock');
        }

        ##### Attributes

        $this->RegisterAttributeInteger('CyclingBrightness', 0);
        $this->RegisterAttributeInteger('EndTime', 0);

        #### Timer

        $this->RegisterTimer('DimLight', 0, self::MODULE_PREFIX . '_DimLight(' . $this->InstanceID . ');');
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

        //Delete all update messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message == VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        //Register references and update messages
        $names = [];
        $names[] = ['propertyName' => 'LightStatus', 'useUpdate' => true];
        $names[] = ['propertyName' => 'LightColor', 'useUpdate' => false];
        $names[] = ['propertyName' => 'LightBrightness', 'useUpdate' => false];
        foreach ($names as $name) {
            $id = $this->ReadPropertyInteger($name['propertyName']);
            if ($id > 1 && @IPS_ObjectExists($id)) { //0 = main category, 1 = none
                $this->RegisterReference($id);
                if ($name['useUpdate']) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
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
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        //Module name
        $data['elements'][0]['caption'] = self::MODULE_NAME;
        //Version
        $data['elements'][1]['caption'] = 'Version: ' . self::MODULE_VERSION;
        return json_encode($data);
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