<?php

/** @noinspection PhpUnused */

/*
 * @module      Einschlaflicht
 *
 * @prefix      EL
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @see         https://github.com/ubittner/Einschlaflicht
 *
 */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class Einschlaflicht extends IPSModule
{
    //Helper
    use EL_control;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        //Register properties
        $this->RegisterPropertyBoolean('Usability', true);
        $this->RegisterPropertyInteger('Light', 0);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('SleepColor', 16750848);
        $this->RegisterPropertyInteger('SleepBrightness', 254);
        $this->RegisterPropertyInteger('SleepDuration', 15);
        //Create profile
        $profile = 'AL.' . $this->InstanceID . '.SleepLight';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileIcon($profile, 'Bulb');
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', '', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', '', 0x00FF00);
        //Register variable
        $profile = 'AL.' . $this->InstanceID . '.SleepLight';
        $this->RegisterVariableBoolean('SleepLight', 'Einschlaflicht', $profile, 10);
        $this->EnableAction('SleepLight');
        //Register attributes
        $this->RegisterAttributeInteger('CyclingBrightness', 0);
        $this->RegisterAttributeInteger('CyclingInterval', 0);
        //Register timers
        $this->RegisterTimer('SleepMode', 0, 'EL_ExecuteSleepMode(' . $this->InstanceID . ');');
        $this->RegisterTimer('UpdateLightState', 0, 'EL_UpdateLightState(' . $this->InstanceID . ');');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
        $profile = 'EL.' . $this->InstanceID . '.SleepLight';
        if (IPS_VariableProfileExists($profile)) {
            IPS_DeleteVariableProfile($profile);
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
        $this->ValidateConfiguration();
        $this->UpdateLightState();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug('MessageSink', 'SenderID: ' . $SenderID . ', Message: ' . $Message, 0);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

        }
    }

    #################### Request action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
                case 'SleepLight':
                    $this->ToggleSleepLight($Value);
                    break;

            }
    }

    #################### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function ValidateConfiguration(): bool
    {
        $result = true;
        $status = 102;
        $usability = $this->CheckUsability();
        if (!$usability) {
            $result = false;
            $status = 104;
        }
        IPS_SetDisabled($this->InstanceID, !$usability);
        $this->SetStatus($status);
        return $result;
    }

    private function CheckUsability(): bool
    {
        if (!$this->ReadPropertyBoolean('Usability')) {
            $this->ResetParameters();
            $this->SetTimerInterval('UpdateLightState', 0);
            $this->SendDebug(__FUNCTION__, 'Abbruch, die Instanz ist inaktiv!', 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', Abbruch, die Instanz ist inaktiv!', KL_WARNING);
            return false;
        }
        return true;
    }
}