<?php
namespace FreePBX\modules\Sccp_manager;

/** Common Cisco XML page and input rendering for service applications. */
final class serviceAppInput {
    public function text($title,$body) {
        return serviceRegistry::text($title,$body);
    }
    public function menu($title,$entries) {
        return serviceRegistry::menu($title,$entries);
    }
    public function prompt($title,$prompt,$url,$label,$field,$mode='A') {
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D',(string)$field) ||
            !in_array($mode,['A','N','T','E','P'],true))
            throw new \InvalidArgumentException('Invalid input field');
        $esc=static function($s){return htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES,'UTF-8');};
        return '<?xml version="1.0" encoding="UTF-8"?><CiscoIPPhoneInput>'.
            '<Title>'.$esc(substr((string)$title,0,32)).'</Title>'.
            '<Prompt>'.$esc(substr((string)$prompt,0,32)).'</Prompt>'.
            '<URL>'.$esc($url).'</URL><InputItem>'.
            '<DisplayName>'.$esc(substr((string)$label,0,32)).'</DisplayName>'.
            '<QueryString>'.$esc($field).'</QueryString>'.
            '<InputFlags>'.$esc($mode).'</InputFlags>'.
            '</InputItem></CiscoIPPhoneInput>';
    }
}
