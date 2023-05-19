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
     * @param integer $Mode
     * 0 =  Manually,
     * 1 =  Weekly Schedule
     *
     * @return bool
     * false =  an error occurred,
     * true =   successful
     *
     * @throws Exception
     */
    public function ToggleSleepLight(bool $State, int $Mode = 0): bool
    {
        $this->SendDebug(__FUNCTION__, 'wird ausgeführt.', 0);
        $this->SendDebug(__FUNCTION__, 'Status: ' . json_encode($State), 0);
        $this->SendDebug(__FUNCTION__, 'Modus: ' . $Mode, 0);

        //Off
        if (!$State) {
            $this->SetValue('SleepLight', false);
            IPS_SetDisabled($this->GetIDForIdent('Brightness'), false);
            IPS_SetDisabled($this->GetIDForIdent('Color'), false);
            IPS_SetDisabled($this->GetIDForIdent('Duration'), false);
            $this->SetValue('ProcessFinished', '');
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), true);
            $this->WriteAttributeInteger('CyclingBrightness', 0);
            $this->WriteAttributeInteger('EndTime', 0);
            $this->SetTimerInterval('DecreaseBrightness', 0);
        }

        //On
        else {
            $lightStatusID = $this->ReadPropertyInteger('LightStatus');
            if ($lightStatusID <= 1 || @!IPS_ObjectExists($lightStatusID)) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Lichtstatus ist nicht vorhanden!', 0);
                return false;
            }

            $colorID = $this->ReadPropertyInteger('LightColor');
            if ($colorID <= 1 || @!IPS_ObjectExists($colorID)) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Lichtfarbe ist nicht vorhanden!', 0);
                return false;
            }

            $brightnessID = $this->ReadPropertyInteger('LightBrightness');
            if ($brightnessID <= 1 || @!IPS_ObjectExists($brightnessID)) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Lichthelligkeit ist nicht vorhanden!', 0);
                return false;
            }

            //Manually
            if ($Mode == 0) {
                $brightness = $this->GetValue('Brightness');
                $color = $this->GetValue('Color');
                $timestamp = time() + $this->GetValue('Duration') * 60;
            }

            //Weekly schedule
            else {
                $day = date('N');

                //Weekday
                if ($day >= 1 && $day <= 5) {
                    $brightness = $this->ReadPropertyInteger('WeekdayBrightness');
                    $color = $this->ReadPropertyInteger('WeekdayColor');
                    $timestamp = time() + $this->ReadPropertyInteger('WeekdayDuration') * 60;
                }

                //Weekend
                else {
                    $brightness = $this->ReadPropertyInteger('WeekendBrightness');
                    $color = $this->ReadPropertyInteger('WeekendColor');
                    $timestamp = time() + $this->ReadPropertyInteger('WeekendDuration') * 60;
                }
            }

            //Set values
            $this->SetValue('SleepLight', true);
            IPS_SetDisabled($this->GetIDForIdent('Brightness'), true);
            IPS_SetDisabled($this->GetIDForIdent('Color'), true);
            IPS_SetDisabled($this->GetIDForIdent('Duration'), true);
            $this->SetValue('ProcessFinished', date('d.m.Y, H:i:s', $timestamp));
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), false);

            //Set attributes
            $this->WriteAttributeInteger('CyclingBrightness', $brightness - 1);
            $this->WriteAttributeInteger('EndTime', $timestamp);

            //Set light values
            @RequestAction($colorID, $color);
            @RequestAction($brightnessID, $brightness);
            @RequestAction($lightStatusID, true);

            //Set next cycle
            $this->SetTimerInterval('DecreaseBrightness', $this->CalculateNextCycle() * 1000);
        }

        return true;
    }

    /**
     * Decreases the brightness of the light.
     *
     * @return void
     * @throws Exception
     */
    public function DecreaseBrightness(): void
    {
        $this->SendDebug(__FUNCTION__, 'wird ausgeführt.', 0);

        $lightStatusID = $this->ReadPropertyInteger('LightStatus');
        if ($lightStatusID <= 1 || @!IPS_ObjectExists($lightStatusID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Lichtstatus ist nicht vorhanden!', 0);
        }

        $lightBrightnessID = $this->ReadPropertyInteger('LightBrightness');
        if ($lightBrightnessID <= 1 || @!IPS_ObjectExists($lightBrightnessID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Lichthelligkeit ist nicht vorhanden!', 0);
        }

        $actualBrightness = GetValue($lightBrightnessID);

        //Abort, if light was already switched off and user don't want to wait until cycling end
        if (!GetValue($lightStatusID) || $actualBrightness == 0) {
            $this->ToggleSleepLight(false);
            return;
        }

        //Abort, if actual brightness is higher than the last cycling brightness
        if ($actualBrightness > $this->ReadAttributeInteger('CyclingBrightness') + 1) {
            $this->ToggleSleepLight(false);
            return;
        }

        //Last cycle
        if ($actualBrightness == 1) {
            //Switch light off
            @RequestAction($lightStatusID, false);
            $this->ToggleSleepLight(false);
        }

        //Cycle
        if ($actualBrightness > 1) {
            //Decrease brightness
            @RequestAction($lightBrightnessID, $actualBrightness - 1);
            $this->WriteAttributeInteger('CyclingBrightness', $actualBrightness - 1);
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
        $this->SendDebug(__FUNCTION__, 'wird ausgeführt.', 0);
        $lightBrightnessID = $this->ReadPropertyInteger('LightBrightness');
        if ($lightBrightnessID <= 1 || @!IPS_ObjectExists($lightBrightnessID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Lichthelligkeit ist nicht vorhanden!', 0);
            return 0;
        }
        $lightBrightness = GetValue($lightBrightnessID);
        if ($lightBrightness == 0) {
            $this->ToggleSleepLight(false);
        }
        $dividend = $this->ReadAttributeInteger('EndTime') - time();
        //Check dividend
        if ($dividend <= 0) {
            $this->ToggleSleepLight(false);
            return 0;
        }
        $remainingTime = intval(round($dividend / $lightBrightness));
        $this->SendDebug(__FUNCTION__, 'Nächste Ausführung in: ' . $remainingTime, 0);
        return $remainingTime;
    }
}