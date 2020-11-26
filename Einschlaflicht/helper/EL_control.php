<?php

/** @noinspection PhpUndefinedFunctionInspection */
/** @noinspection PhpUnused */

/*
 * @module      Einschlaflicht
 *
 * @prefix      EL
 *
 * @file        EL_control.php
 *
 * @developer   Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @see         https://github.com/ubittner/Einschlaflicht
 *
 */

declare(strict_types=1);

trait EL_control
{
    /**
     * Toggles the sleep light off or on.
     *
     * @param bool $State
     * false    = off
     * true     = on
     */
    public function ToggleSleepLight(bool $State): void
    {
        if (!$this->CheckUsability()) {
            return;
        }
        $id = $this->ReadPropertyInteger('Light');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $lightState = @PHUE_GetState($id);
            //Off
            if (!$State) {
                $this->SetValue('SleepLight', false);
                $this->ResetParameters();
                if ($lightState) {
                    @PHUE_SwitchMode($id, false);
                }
            }
            //On
            if ($State) {
                $this->SetValue('SleepLight', true);
                $color = $this->ReadPropertyInteger('SleepColor');
                $hex = dechex($color);
                $hexColor = '#' . strtoupper($hex);
                @PHUE_ColorSet($id, $hexColor);
                $brightness = $this->ReadPropertyInteger('SleepBrightness');
                @PHUE_DimSet($id, $brightness);
                $this->WriteAttributeInteger('CyclingBrightness', $brightness);
                $milliseconds = intval(floor(($this->ReadPropertyInteger('SleepDuration') * 60 * 1000) / ($brightness + 1)));
                $this->WriteAttributeInteger('CyclingInterval', $milliseconds);
                $this->SetTimerInterval('SleepMode', $milliseconds);
            }
        }
    }

    /**
     * Executes the sleep mode, used by timer.
     */
    public function ExecuteSleepMode(): void
    {
        if (!$this->CheckUsability()) {
            return;
        }
        $id = $this->ReadPropertyInteger('Light');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            //Abort, if light was already switched off, user don't want to wait till cycling end
            if (@!PHUE_GetState($id)) {
                $this->SetValue('SleepLight', false);
                $this->ResetParameters();
                return;
            }
            //Abort, if or actual brightness is higher then the last cycling brightness
            $brightness = $this->ReadAttributeInteger('CyclingBrightness');
            if ($this->GetLightBrightness() > $brightness + 1) {
                $this->SetValue('SleepLight', true);
                $this->ResetParameters();
                return;
            }
            //Dimming down
            if ($brightness > 0) {
                @PHUE_DimSet($id, $brightness - 1);
                $milliseconds = $this->ReadAttributeInteger('CyclingInterval');
                $this->SetTimerInterval('SleepMode', $milliseconds);
                $this->WriteAttributeInteger('CyclingBrightness', $brightness - 1);
            } else {
                $this->ToggleSleepLight(false);
            }
        }
    }

    /**
     * Updates the light state, used by timer.
     */
    public function UpdateLightState(): void
    {
        if (!$this->CheckUsability()) {
            return;
        }
        $updateInterval = $this->ReadPropertyInteger('UpdateInterval') * 1000;
        if ($updateInterval > 0) {
            $id = $this->ReadPropertyInteger('Light');
            if ($id != 0 && @IPS_ObjectExists($id)) {
                if (@!PHUE_GetState($id)) {
                    $this->SetValue('SleepLight', false);
                    $this->ResetParameters();
                } else {
                    $this->SetValue('SleepLight', true);
                }
            }
        }
        $this->SetTimerInterval('UpdateLightState', $updateInterval);
    }

    #################### Private

    private function GetLightBrightness(): int
    {
        $brightness = 0;
        $id = $this->ReadPropertyInteger('Light');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $children = IPS_GetChildrenIDs($id);
            if (!empty($children)) {
                $children = IPS_GetChildrenIDs($id);
                if (!empty($children)) {
                    foreach ($children as $child) {
                        $ident = @IPS_GetObject($child)['ObjectIdent'];
                        if ($ident == 'HUE_Brightness') {
                            $brightness = GetValueInteger($child);
                        }
                    }
                }
            }
        }
        return $brightness;
    }

    private function ResetParameters(): void
    {
        $this->WriteAttributeInteger('CyclingBrightness', 0);
        $this->WriteAttributeInteger('CyclingInterval', 0);
        $this->SetTimerInterval('SleepMode', 0);
    }
}