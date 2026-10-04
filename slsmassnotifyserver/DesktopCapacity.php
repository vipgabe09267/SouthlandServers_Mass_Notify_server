<?php
namespace FreePBX\modules;

trait SlsDesktopCapacity
{
    public function previewDeviceCapacity(array $input): array
    {
        $limits = [];
        foreach (['desktop_client_limit', 'phone_device_limit'] as $key) {
            $value = $input[$key] ?? null;
            if ((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]{1,4}$/D', (string)$value)
                || (int)$value < 1 || (int)$value > 1000) {
                return ['success'=>false, 'message'=>_('Enter whole phone and desktop capacities from 1 through 1000.')];
            }
            $limits[$key] = (int)$value;
        }
        $report = $this->checkDesktopCapacityResources($limits['desktop_client_limit'], $limits['phone_device_limit']);
        if (($report['schema'] ?? null) !== 1 || !isset($report['requirements']['cpu_count'],
                $report['requirements']['memory_bytes'], $report['hardware']['effective_cpu_count'],
                $report['hardware']['effective_memory_bytes'])) {
            return ['success'=>false, 'message'=>$report['errors'][0]['message'] ?? _('Resource requirements could not be checked. Run Repair Installation and retry.')];
        }
        return ['success'=>true, 'checked_at'=>gmdate('c'), 'limits'=>$limits,
            'requirements'=>$report['requirements'], 'eligible_limit'=>$report['eligible_limit'],
            'eligible_phone_limit'=>$report['eligible_phone_limit'], 'hardware'=>[
                'effective_cpu_count'=>$report['hardware']['effective_cpu_count'],
                'effective_memory_bytes'=>$report['hardware']['effective_memory_bytes']],
            'resource_checks'=>$report['resource_checks'] ?? [],
            'errors'=>array_values(array_map(static fn($row)=>(string)$row['message'], $report['errors'] ?? []))];
    }

    private function validateDesktopCapacityConfig(array $settings)
    {
        $limit = $settings['desktop_client_limit'] ?? 25;
        if (!is_int($limit) || $limit < 1 || $limit > 1000) {
            return [_('Desktop capacity must be a whole number from 1 to 1000.')];
        }
        $phoneLimit = $settings['phone_device_limit'] ?? 25;
        if (!is_int($phoneLimit) || $phoneLimit < 1 || $phoneLimit > 1000) {
            return [_('Phone capacity must be a whole number from 1 to 1000 registered device contacts.')];
        }
        $count = count((array)($settings['desktop_clients'] ?? []));
        return $count > $limit
            ? [sprintf(_('There are %d saved desktop clients, but capacity is %d. Remove clients or increase Desktop Capacity before saving.'), $count, $limit)]
            : [];
    }

    protected function checkDesktopCapacityResources($limit, $phoneLimit = 25)
    {
        $helper = self::RUNTIME_DIR . '/sls_resource_capacity.py';
        if (!is_readable($helper) || !is_executable('/usr/bin/python3')) {
            return ['errors' => [['message' => _('The device capacity checker is unavailable. Run Repair Installation before increasing capacity.')]]];
        }
        $output = []; $exit = 0;
        exec('/usr/bin/timeout --signal=TERM --kill-after=1 5 /usr/bin/python3 -I ' . escapeshellarg($helper)
            . ' --check --desktop-limit ' . (int)$limit . ' --phone-limit ' . (int)$phoneLimit . ' 2>/dev/null', $output, $exit);
        $result = json_decode(implode("\n", $output), true);
        if (!is_array($result) || ($result['schema'] ?? 0) !== 1 || !in_array($exit, [0, 2, 3], true)
            || ($exit === 3 && empty($result['errors']))) {
            $message = in_array($exit, [124, 137], true)
                ? _('The PBX resource check exceeded its five-second deadline. Review storage latency and retained SLS history, then retry before increasing device capacity.')
                : sprintf(_('The PBX resource checker exited with code %d without a valid report. Run Repair Installation to restore the checker and its permissions before increasing device capacity.'), $exit);
            return ['errors' => [['message' => $message]]];
        }
        return $result;
    }

    private function assertDesktopCapacityChange(array $settings, array $active)
    {
        $errors = $this->validateDesktopCapacityConfig($settings);
        if ($errors) { throw new \DomainException(implode(' ', $errors)); }
        $requested = $settings['desktop_client_limit'] ?? 25;
        $requestedPhones = $settings['phone_device_limit'] ?? 25;
        // A temporary hardware shortage must never remove enrolled clients or
        // prevent reducing capacity. New admission requires a fresh check.
        if ($requested <= (int)($active['desktop_client_limit'] ?? 25)
            && $requestedPhones <= (int)($active['phone_device_limit'] ?? 25)) { return; }
        $result = $this->checkDesktopCapacityResources($requested, $requestedPhones);
        foreach (($result['errors'] ?? []) as $error) {
            $errors[] = (string)($error['message'] ?? _('The PBX does not meet the combined desktop and phone capacity requirements.'));
        }
        if ((int)($result['eligible_limit'] ?? 0) < $requested && !$errors) {
            $errors[] = sprintf(_('This PBX currently qualifies for at most %d desktops. Add resources before increasing capacity to %d.'), (int)($result['eligible_limit'] ?? 0), $requested);
        }
        if ((int)($result['eligible_phone_limit'] ?? 0) < $requestedPhones && !$errors) {
            $errors[] = sprintf(_('With the requested desktop capacity, this PBX currently qualifies for at most %d simultaneous phone device contacts. Add resources before increasing phone capacity to %d.'), (int)($result['eligible_phone_limit'] ?? 0), $requestedPhones);
        }
        if ($errors) { throw new \DomainException(implode(' ', $errors)); }
    }
}
