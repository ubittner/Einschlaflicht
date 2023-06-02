<?php

/**
 * @project       Einschlaflicht/Einschlaflicht/helper
 * @file          control.php
 * @author        Ulrich Bittner
 * @copyright     2023 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

/** @noinspection PhpVoidFunctionResultUsedInspection */

declare(strict_types=1);

trait Control
{
    /**
     * Toggles the sleep light off or on.
     *
     * @param bool $State
     * false =  off,
     * true =   on
     *
     * @return bool
     * false =  an error occurred,
     * true =   successful
     *
     * @throws Exception
     */
    public function ToggleSleepLight(bool $State): bool
    {
        $debugText = 'Einschlaflicht ausschalten';
        if ($State) {
            $debugText = 'Einschlaflicht einschalten';
        }
        $this->SendDebug(__FUNCTION__, $debugText, 0);
        //Off
        if (!$State) {
            $this->SetValue('SleepLight', false);
            IPS_SetDisabled($this->GetIDForIdent('Brightness'), false);
            IPS_SetDisabled($this->GetIDForIdent('ColorSelection'), false);
            IPS_SetDisabled($this->GetIDForIdent('Color'), false);
            IPS_SetDisabled($this->GetIDForIdent('Duration'), false);
            $this->SetValue('ProcessFinished', '');
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), true);
            $this->WriteAttributeInteger('CyclingBrightness', 0);
            $this->WriteAttributeInteger('EndTime', 0);
            $this->SetTimerInterval('DecreaseBrightness', 0);
        } //On
        else {
            if (!$this->CheckDevicePowerID()) {
                return false;
            }
            if (!$this->CheckDeviceBrightnessID()) {
                return false;
            }
            //Timestamp
            $timestamp = time() + $this->GetValue('Duration') * 60;
            //Set values
            $this->SetValue('SleepLight', true);
            IPS_SetDisabled($this->GetIDForIdent('Brightness'), true);
            IPS_SetDisabled($this->GetIDForIdent('ColorSelection'), true);
            IPS_SetDisabled($this->GetIDForIdent('Color'), true);
            IPS_SetDisabled($this->GetIDForIdent('Duration'), true);
            $this->SetValue('ProcessFinished', date('d.m.Y, H:i:s', $timestamp));
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), false);
            //Set attributes
            $brightness = $this->GetValue('Brightness');
            $this->WriteAttributeInteger('CyclingBrightness', $brightness);
            $this->SendDebug(__FUNCTION__, 'Helligkeit: ' . $brightness, 0);
            $this->WriteAttributeInteger('EndTime', $timestamp);
            //Set device brightness
            $setDeviceBrightness = @RequestAction($this->ReadPropertyInteger('DeviceBrightness'), $brightness);
            //Try again
            if (!$setDeviceBrightness) {
                @RequestAction($this->ReadPropertyInteger('DeviceBrightness'), $brightness);
            }
            //Set device color
            if ($this->CheckDeviceColorID()) {
                $deviceColorID = $this->ReadPropertyInteger('DeviceColor');
                $colorSelection = $this->GetValue('ColorSelection');
                if ($colorSelection > 0) {
                    if ($colorSelection == 1) {
                        $color = $this->GetValue('Color');
                    } else {
                        $color = $colorSelection;
                    }
                    $setDeviceColor = @RequestAction($deviceColorID, $color);
                    $this->SendDebug(__FUNCTION__, 'Farbe: ' . $color, 0);
                    //Try again
                    if (!$setDeviceColor) {
                        @RequestAction($deviceColorID, $color);
                    }
                }
            }
            //Power on device
            $this->SendDebug(__FUNCTION__, 'Lampe einschalten', 0);
            $powerOnDevice = @RequestAction($this->ReadPropertyInteger('DevicePower'), true);
            //Try again
            if (!$powerOnDevice) {
                @RequestAction($this->ReadPropertyInteger('DevicePower'), true);
            }
            //Set next cycle
            $this->SetTimerInterval('DecreaseBrightness', $this->CalculateNextCycle() * 1000);
        }
        return true;
    }

    /**
     * Decreases the brightness of the device.
     *
     * @return void
     * @throws Exception
     */
    public function DecreaseBrightness(): void
    {
        if (!$this->CheckDevicePowerID()) {
            return;
        }
        if (!$this->CheckDeviceBrightnessID()) {
            return;
        }
        $cyclingBrightness = $this->ReadAttributeInteger('CyclingBrightness');
        //Last cycle
        if ($cyclingBrightness == 1) {
            $this->ToggleSleepLight(false);
            //Power off device
            $this->SendDebug(__FUNCTION__, 'Lampe ausschalten', 0);
            $powerOffDevice = @RequestAction($this->ReadPropertyInteger('DevicePower'), false);
            //Try again
            if (!$powerOffDevice) {
                @RequestAction($this->ReadPropertyInteger('DevicePower'), false);
            }
            return;
        }
        //Cycle
        $actualDeviceBrightness = GetValue($this->ReadPropertyInteger('DeviceBrightness'));
        if ($actualDeviceBrightness > 1) {
            //Decrease Brightness
            $decreasedBrightness = $cyclingBrightness - 1;
            $this->WriteAttributeInteger('CyclingBrightness', $decreasedBrightness);
            $this->SendDebug(__FUNCTION__, 'Helligkeit: ' . $decreasedBrightness, 0);
            $setDeviceBrightness = @RequestAction($this->ReadPropertyInteger('DeviceBrightness'), $decreasedBrightness);
            //Try again
            if (!$setDeviceBrightness) {
                @RequestAction($this->ReadPropertyInteger('DeviceBrightness'), $decreasedBrightness);
            }
            //Set next cycle
            $this->SetTimerInterval('DecreaseBrightness', $this->CalculateNextCycle() * 1000);
        }
    }

    #################### Private

    /**
     * Calculates the next cycle.
     *
     * @return int
     * @throws Exception
     */
    private function CalculateNextCycle(): int
    {
        if (!$this->CheckDeviceBrightnessID()) {
            return 0;
        }
        $DeviceBrightnessID = $this->ReadPropertyInteger('DeviceBrightness');
        $actualDeviceBrightness = GetValue($DeviceBrightnessID);
        $dividend = $this->ReadAttributeInteger('EndTime') - time();
        //Check dividend
        if ($dividend <= 0) {
            $this->ToggleSleepLight(false);
            return 0;
        }
        return intval(round($dividend / $actualDeviceBrightness));
    }

    /**
     * Checks for an existing device power id.
     *
     * @return bool
     * false =  doesn't exist,
     * true =   exists
     *
     * @throws Exception
     */
    private function CheckDevicePowerID(): bool
    {
        $devicePowerID = $this->ReadPropertyInteger('DevicePower');
        if ($devicePowerID <= 1 || @!IPS_ObjectExists($devicePowerID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Power (Aus/An) ist nicht vorhanden!', 0);
            return false;
        }
        return true;
    }

    /**
     * Checks for an existing device brightness id.
     *
     * @return bool
     * false =  doesn't exist,
     * true =   exists
     *
     * @throws Exception
     */
    private function CheckDeviceBrightnessID(): bool
    {
        $DeviceBrightnessID = $this->ReadPropertyInteger('DeviceBrightness');
        if ($DeviceBrightnessID <= 1 || @!IPS_ObjectExists($DeviceBrightnessID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Helligkeit ist nicht vorhanden!', 0);
            return false;
        }
        return true;
    }

    /**
     * Checks for an existing device color id.
     *
     * @return bool
     * false =  doesn't exist,
     * true =   exists
     *
     * @throws Exception
     */
    private function CheckDeviceColorID(): bool
    {
        $DeviceColorID = $this->ReadPropertyInteger('DeviceColor');
        if ($DeviceColorID <= 1 || @!IPS_ObjectExists($DeviceColorID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Farbe ist nicht vorhanden!', 0);
            return false;
        }
        return true;
    }
}