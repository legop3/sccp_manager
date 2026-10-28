<?php
namespace FreePBX\modules\Sccp_manager;

/**
 * Cisco XML notification document builder.
 *
 * This intentionally does not touch chan_sccp's native message-waiting state.
 * Documents are returned as strings so callers can host them behind the
 * module's existing phone XML endpoint and push the resulting URL to a phone.
 */
final class phoneNotifications
{
    private static function xml($value)
    {
        return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function limit($value, $bytes)
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string)$value);
        if (strlen($value) <= $bytes) return $value;
        if (function_exists('mb_strcut')) return mb_strcut($value, 0, $bytes, 'UTF-8');
        while (strlen($value) > $bytes) $value = substr($value, 0, -1);
        return $value;
    }

    public static function text($title, $message, $prompt = 'Dismiss')
    {
        $title = self::xml(self::limit($title, 32));
        $prompt = self::xml(self::limit($prompt, 32));
        $message = self::xml(self::limit($message, 2048));
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CiscoIPPhoneText><Title>' . $title . '</Title>'
            . '<Text>' . $message . '</Text><Prompt>' . $prompt . '</Prompt>'
            . '<SoftKeyItem><Name>Exit</Name><URL>SoftKey:Exit</URL><Position>1</Position></SoftKeyItem>'
            . '</CiscoIPPhoneText>';
    }

    public static function status($message, $timer = 0)
    {
        $message = self::xml(self::limit($message, 80));
        $timer = max(0, min(3600, (int)$timer));
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CiscoIPPhoneStatus><Text>' . $message . '</Text>'
            . '<Timer>' . $timer . '</Timer></CiscoIPPhoneStatus>';
    }

    public static function execute($notificationUrl, $soundFile = null)
    {
        if (!filter_var($notificationUrl, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($notificationUrl, PHP_URL_SCHEME)), array('http', 'https'), true)) {
            throw new \InvalidArgumentException('Invalid notification URL');
        }
        $items = '';
        if ($soundFile !== null && $soundFile !== '') {
            if (!preg_match('/^[A-Za-z0-9_.-]+\\.raw$/D', (string)$soundFile)) {
                throw new \InvalidArgumentException('Invalid notification sound filename');
            }
            $items .= '<ExecuteItem URL="Play:' . self::xml($soundFile) . '"/>';
        }
        $items .= '<ExecuteItem URL="' . self::xml($notificationUrl) . '"/>';
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CiscoIPPhoneExecute>' . $items . '</CiscoIPPhoneExecute>';
    }
}
