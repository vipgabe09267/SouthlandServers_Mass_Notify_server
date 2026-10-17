<?php
namespace FreePBX\modules;
if (!class_exists('SlsAtomicJson', false)) { require_once __DIR__.'/bin/sls_mass_notify/sls_atomic_json.php'; }

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
        $whole=static fn($value): bool=>is_int($value)&&$value>=0;
        $number=static fn($value): bool=>(is_int($value)||is_float($value))&&is_finite((float)$value)&&$value>=0;
        if (($report['schema'] ?? null) !== 1 || !isset($report['requirements']['cpu_count'],
                $report['requirements']['memory_bytes'], $report['hardware']['effective_cpu_count'],
                $report['hardware']['effective_memory_bytes'])
            || !$whole($report['eligible_limit']??null) || !$whole($report['eligible_phone_limit']??null)
            || !$whole($report['requirements']['cpu_count']) || !$whole($report['requirements']['memory_bytes'])
            || !$number($report['hardware']['effective_cpu_count']) || !$whole($report['hardware']['effective_memory_bytes'])
            || !is_array($report['resource_checks'] ?? null) || !is_array($report['errors'] ?? null)
            || !array_is_list($report['errors'])) {
		    $message=$report['errors'][0]['message'] ?? null;
		    if(!is_string($message)||$message===''||strlen($message)>2048||!preg_match('//u',$message)){
		        $message=_('Resource requirements could not be checked. Run Repair Installation and retry.');
		    }
		    return ['success'=>false, 'message'=>$message];
        }
        $errors=[];
        foreach($report['errors'] as $row){
            if(!is_array($row)||!is_string($row['message']??null)||$row['message']===''){
                return ['success'=>false,'message'=>_('The resource checker returned an invalid report. Run Repair Installation and retry.')];
            }
            $errors[]=$row['message'];
        }
        return ['success'=>true, 'checked_at'=>gmdate('c'), 'limits'=>$limits,
            'requirements'=>$report['requirements'], 'eligible_limit'=>$report['eligible_limit'],
            'eligible_phone_limit'=>$report['eligible_phone_limit'], 'hardware'=>[
                'effective_cpu_count'=>$report['hardware']['effective_cpu_count'],
                'effective_memory_bytes'=>$report['hardware']['effective_memory_bytes']],
            'resource_checks'=>$report['resource_checks'] ?? [],
            'errors'=>$errors];
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
        clearstatcache(true,$helper);clearstatcache(true,dirname($helper));
        $meta=@lstat($helper);$parent=@lstat(dirname($helper));
        $production=$helper==='/usr/local/bin/sls_mass_notify/sls_resource_capacity.py';
        $owner=$production?0:(function_exists('posix_geteuid')?posix_geteuid():-1);
        $trusted=is_array($meta)&&is_array($parent)&&($meta['mode']&0170000)===0100000&&$meta['nlink']===1
            && $meta['uid']===$owner&&!($meta['mode']&0022)&&$meta['size']>0&&$meta['size']<=1048576
            && ($parent['mode']&0170000)===0040000&&$parent['uid']===$owner&&!($parent['mode']&0022)
            && realpath($helper)===$helper&&realpath(dirname($helper))===dirname($helper);
        if (!$trusted || !is_readable($helper) || !is_executable('/usr/bin/python3') || !function_exists('proc_open')) {
            return ['errors' => [['message' => _('The device capacity checker is unavailable. Run Repair Installation before increasing capacity.')]]];
        }
        $process=proc_open(['/usr/bin/timeout','--signal=TERM','--kill-after=1','5','/usr/bin/python3','-I',$helper,
            '--check','--desktop-limit',(string)(int)$limit,'--phone-limit',(string)(int)$phoneLimit],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,'/',[
                'PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'],['bypass_shell'=>true]);
        if(!is_resource($process)){return ['errors'=>[['message'=>_('The device capacity checker could not start. Run Repair Installation and retry.')]]];}
        foreach([1,2] as $descriptor){stream_set_blocking($pipes[$descriptor],false);}
        $stdout='';$stderrBytes=0;$overflow=false;$timedOut=false;$exit=null;$deadline=microtime(true)+6.5;
        $drain=static function() use(&$pipes,&$stdout,&$stderrBytes,&$overflow): void {
            foreach([1,2] as $descriptor){
                while(is_resource($pipes[$descriptor])&&!feof($pipes[$descriptor])){
                    $chunk=fread($pipes[$descriptor],8192);if(!is_string($chunk)||$chunk===''){break;}
                    if($descriptor===1){$stdout.=$chunk;if(strlen($stdout)>1048576){$overflow=true;return;}}
                    else{$stderrBytes+=strlen($chunk);if($stderrBytes>65536){$overflow=true;return;}}
                }
            }
        };
        while(true){
            $drain();
            if($overflow){break;}
            $status=proc_get_status($process);
            if(!$status['running']){$drain();$exit=$status['exitcode'];break;}
            if(microtime(true)>=$deadline){$timedOut=true;break;}
            usleep(10000);
        }
        if($overflow||$timedOut){proc_terminate($process,15);usleep(100000);$status=proc_get_status($process);if($status['running']){proc_terminate($process,9);}}
        foreach([1,2] as $descriptor){if(is_resource($pipes[$descriptor])){fclose($pipes[$descriptor]);}}
        $closed=proc_close($process);if($exit===null||$exit<0){$exit=$closed;}
        try{$result=!$overflow&&!$timedOut?\SlsAtomicJson::decodeObject($stdout,32):null;}catch(\Throwable $error){$result=null;}
        $validErrors=is_array($result['errors']??null)&&array_is_list($result['errors']);
        if($validErrors){foreach($result['errors'] as $row){if(!is_array($row)||!is_string($row['message']??null)||$row['message']===''){$validErrors=false;break;}}}
        $validLimits=$exit===3||(is_int($result['eligible_limit']??null)&&($result['eligible_limit']??-1)>=0
            &&is_int($result['eligible_phone_limit']??null)&&($result['eligible_phone_limit']??-1)>=0);
        if (!is_array($result) || ($result['schema'] ?? 0) !== 1 || !in_array($exit, [0, 2, 3], true)
            || !$validErrors || !$validLimits || ($exit === 3 && empty($result['errors']))) {
            $message = $overflow
                ? _('The PBX resource checker exceeded its bounded response limit. Run Repair Installation before increasing device capacity.')
                : (($timedOut || in_array($exit, [124, 137], true))
                    ? _('The PBX resource check exceeded its five-second deadline. Review storage latency and retained SLS history, then retry before increasing device capacity.')
                    : sprintf(_('The PBX resource checker exited with code %d without a valid report. Run Repair Installation to restore the checker and its permissions before increasing device capacity.'), $exit));
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
		$resourceErrors=$result['errors'] ?? [];
		if(!is_array($resourceErrors)||!array_is_list($resourceErrors)){
			$errors[]=_('The PBX resource checker returned invalid shortage details. Run Repair Installation before increasing capacity.');
		}else{
			foreach($resourceErrors as $error){
				$message=is_array($error)?($error['message']??null):null;
				$errors[]=is_string($message)&&$message!==''&&strlen($message)<=2048&&preg_match('//u',$message)
					? $message : _('The PBX resource checker returned invalid shortage details. Run Repair Installation before increasing capacity.');
			}
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
