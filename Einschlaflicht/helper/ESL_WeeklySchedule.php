<?php

/**
 * @project       Einschlaflicht/Einschlaflicht
 * @file          ESL_WeeklySchedule.php
 * @author        Ulrich Bittner
 * @copyright     2023 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

/** @noinspection PhpExpressionResultUnusedInspection */

declare(strict_types=1);

trait ESL_WeeklySchedule
{
    /**
     * Creates a new weekly schedule.
     *
     * @param string $WeekdayStartTime
     * @param string $WeekendStartTime
     * @return void
     * @throws Exception
     */
    public function CreateWeeklySchedule(string $WeekdayStartTime, string $WeekendStartTime): void
    {
        //Determine existing event and delete
        foreach (IPS_GetEventList() as $event) {
            $objects = IPS_GetObject($event);
            if (array_key_exists('ObjectInfo', $objects)) {
                if ($objects['ObjectInfo'] == $this->InstanceID) {
                    $this->UnregisterReference($event);
                    $this->UnregisterMessage($event, EM_UPDATE);
                    IPS_DeleteEvent($event);
                }
            }
        }
        //Create
        $id = IPS_CreateEvent(2);
        IPS_SetEventActive($id, true);
        IPS_SetName($id, 'Wochenplan');
        IPS_SetInfo($id, $this->InstanceID);
        IPS_SetIcon($id, 'Calendar');
        IPS_SetParent($id, $this->InstanceID);
        IPS_SetPosition($id, 50);
        //Actions
        IPS_SetEventScheduleAction($id, 0, 'Aus', 0, '');
        IPS_SetEventScheduleAction($id, 1, 'An', 16750848, '');
        if ($this->ReadPropertyBoolean('UseWeekday')) {
            //Monday to Friday (1 + 2 + 4 + 8 + 16 = 31)
            IPS_SetEventScheduleGroup($id, 0, 31);
            $time = json_decode($WeekdayStartTime);
            IPS_SetEventScheduleGroupPoint($id, 0, 0, $time->hour, $time->minute, $time->second, 1);
            $selectedTime = $time->hour . ':' . $time->minute . ':' . $time->second;
            $endTime = strtotime('+' . $this->ReadPropertyInteger('WeekdayDuration') . ' minutes', strtotime($selectedTime));
            $hour = intval(date('G', $endTime));
            $minute = intval(date('i', $endTime));
            $second = intval(date('s', $endTime));
            IPS_SetEventScheduleGroupPoint($id, 0, 1, $hour, $minute, $second, 0);
        }
        if ($this->ReadPropertyBoolean('UseWeekend')) {
            //Saturday and Sunday (32 + 64 = 96)
            IPS_SetEventScheduleGroup($id, 1, 96);
            $time = json_decode($WeekendStartTime);
            IPS_SetEventScheduleGroupPoint($id, 1, 0, $time->hour, $time->minute, $time->second, 1);
            $selectedTime = $time->hour . ':' . $time->minute . ':' . $time->second;
            $endTime = strtotime('+' . $this->ReadPropertyInteger('WeekendDuration') . ' minutes', strtotime($selectedTime));
            $hour = intval(date('G', $endTime));
            $minute = intval(date('i', $endTime));
            $second = intval(date('s', $endTime));
            IPS_SetEventScheduleGroupPoint($id, 1, 1, $hour, $minute, $second, 0);
        }
        IPS_SetProperty($this->InstanceID, 'WeeklySchedule', $id);
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
        }
    }

    #################### Private

    /**
     * Determines the actual action.
     *
     * @return int
     * Action ID
     *
     * @throws Exception
     */
    private function DetermineAction(): int
    {
        $actionID = 0;
        if ($this->ValidateWeeklySchedule()) {
            $timestamp = time();
            $searchTime = date('H', $timestamp) * 3600 + date('i', $timestamp) * 60 + date('s', $timestamp);
            $weekDay = date('N', $timestamp);
            $id = $this->ReadPropertyInteger('WeeklySchedule');
            if ($id != 0 && @IPS_ObjectExists($id)) {
                $event = IPS_GetEvent($id);
                foreach ($event['ScheduleGroups'] as $group) {
                    if (($group['Days'] & pow(2, $weekDay - 1)) > 0) {
                        $points = $group['Points'];
                        foreach ($points as $point) {
                            $startTime = $point['Start']['Hour'] * 3600 + $point['Start']['Minute'] * 60 + $point['Start']['Second'];
                            if ($startTime <= $searchTime) {
                                $actionID = $point['ActionID'];
                            }
                        }
                    }
                }
            }
        }
        return $actionID;
    }

    /**
     * Validates if the weekly schedule is existing and active.
     *
     * @return bool
     * false =  not valid,
     * true =   valid
     *
     * @throws Exception
     */
    private function ValidateWeeklySchedule(): bool
    {
        $result = false;
        $id = $this->ReadPropertyInteger('WeeklySchedule');
        if ($id > 1 && @IPS_ObjectExists($id)) {
            $event = IPS_GetEvent($id);
            if ($event['EventActive'] == 1) {
                $result = true;
            }
        }
        if (!$result) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, der Wochenplan ist nicht vorhanden oder deaktiviert!', 0);
        }
        return $result;
    }
}