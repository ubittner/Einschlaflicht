<?php

/**
 * @project       Einschlaflicht/Einschlaflicht
 * @file          ESL_Control.php
 * @author        Ulrich Bittner
 * @copyright     2023 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

declare(strict_types=1);

trait ESL_Control
{
    /**
     * Toggles the sleep light off or on.
     *
     * @param bool $State
     * false =  off
     * true =   on
     *
     * @return void
     * @throws Exception
     */
    public function ToggleSleepLight(bool $State): void
    {
        //Off
        if (!$State) {
            $this->SetValue('SleepLight', false);
            $this->SetValue('NextPowerOff', '');
            $this->WriteAttributeInteger('CyclingBrightness', 0);
            $this->WriteAttributeInteger('EndTime', 0);
            $this->SetTimerInterval('DimLight', 0);
        }
        //On
        else {
            $lightStatusID = $this->ReadPropertyInteger('LightStatus');
            if ($lightStatusID <= 1 || @!IPS_ObjectExists($lightStatusID)) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Lichtstatus ist nicht vorhanden!', 0);
                return;
            }

            $colorID = $this->ReadPropertyInteger('LightColor');
            if ($colorID <= 1 || @!IPS_ObjectExists($colorID)) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Lichtfarbe ist nicht vorhanden!', 0);
                return;
            }

            $brightnessID = $this->ReadPropertyInteger('LightBrightness');
            if ($brightnessID <= 1 || @!IPS_ObjectExists($brightnessID)) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Lichthelligkeit ist nicht vorhanden!', 0);
                return;
            }

            //Set values
            $this->SetValue('SleepLight', true);
            $timestamp = time() + $this->GetValue('Duration') * 60;
            $this->SetValue('NextPowerOff', date('d.m.Y, H:i:s', $timestamp));
            $this->WriteAttributeInteger('EndTime', $timestamp);
            $this->WriteAttributeInteger('CyclingBrightness', $this->GetValue('Brightness') - 1);

            //Set light values
            @RequestAction($brightnessID, $this->GetValue('Brightness'));
            @RequestAction($colorID, $this->GetValue('Color'));
            @RequestAction($lightStatusID, true);

            //Set next cycle
            $this->SetTimerInterval('DimLight', $this->CalculateNextCycle() * 1000);
        }
    }

    /**
     * Dims the light.
     *
     * @return void
     * @throws Exception
     */
    public function DimLight(): void
    {
        $this->SendDebug(__FUNCTION__, 'wird ausgeführt.', 0);

        $lightStatusID = $this->ReadPropertyInteger('LightStatus');
        if ($lightStatusID <= 1 || @!IPS_ObjectExists($lightStatusID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Lichtstatus ist nicht vorhanden!', 0);
        }

        $brightnessID = $this->ReadPropertyInteger('LightBrightness');
        if ($brightnessID <= 1 || @!IPS_ObjectExists($brightnessID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Lichthelligkeit ist nicht vorhanden!', 0);
        }

        //Abort, if light was already switched off and user don't want to wait until cycling end
        if (!GetValue($lightStatusID)) {
            $this->ToggleSleepLight(false);
            return;
        }

        //Abort, if actual brightness is higher than the last cycling brightness
        if (GetValue($brightnessID) > $this->ReadAttributeInteger('CyclingBrightness') + 1) {
            $this->ToggleSleepLight(false);
            return;
        }

        //Cycle
        $brightness = GetValue($brightnessID);
        if ($brightness > 1) {
            //Dim light
            @RequestAction($brightnessID, $brightness - 1);
            $this->WriteAttributeInteger('CyclingBrightness', $brightness - 1);
            //Set next cycle
            $this->SetTimerInterval('DimLight', $this->CalculateNextCycle() * 1000);
        }

        //Last cycle
        if ($brightness == 1) {
            //Switch light off
            @RequestAction($lightStatusID, false);
            $this->ToggleSleepLight(false);
        }
    }

    #################### Private

    /**
     * Calculates the next cycle.
     *
     * @return int
     * @throws Exception
     */
    public function CalculateNextCycle(): int
    {
        $this->SendDebug(__FUNCTION__, 'wird ausgeführt.', 0);
        $id = $this->ReadPropertyInteger('LightBrightness');
        if ($id <= 1 || @!IPS_ObjectExists($id)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Lichthelligkeit ist nicht vorhanden!', 0);
            return 0;
        }
        $brightness = GetValue($id);
        if ($brightness == 0) {
            $this->ToggleSleepLight(false);
        }
        $startTime = time();
        $endTime = $this->ReadAttributeInteger('EndTime');
        $remainingTime = intval(round(($endTime - $startTime) / ($brightness)));
        $this->SendDebug(__FUNCTION__, 'Nächste Ausführung: ' . $remainingTime, 0);
        return $remainingTime;
    }
}