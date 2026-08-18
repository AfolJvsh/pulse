<?php
namespace App\Services;
use InvalidArgumentException;
final class PublicUrlGuard {
    public function assert(string $url): void {
        $parts=parse_url($url);
        if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['https','http'],true)||empty($parts['host'])) throw new InvalidArgumentException('Webhook URL must be HTTP(S).');
        $host=(string)$parts['host'];
        $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:(gethostbynamel($host)?:[]);
        if($ips===[]) throw new InvalidArgumentException('Webhook host could not be resolved.');
        foreach($ips as $ip){
            if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false) throw new InvalidArgumentException('Webhook host resolves to a private or reserved address.');
        }
    }
}
