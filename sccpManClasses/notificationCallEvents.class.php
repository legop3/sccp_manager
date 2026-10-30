<?php
namespace FreePBX\modules\Sccp_manager;

/** Pure CDR and voicemail correlation, independent of polling and delivery.
 * Inputs are raw Asterisk CDR rows and parsed voicemail metadata.
 */
final class notificationCallEvents {
    public static function hasRecordedVoicemail(array $call,array $inbox,$mailbox=null) {
        $extension=$mailbox===null?(string)($call['dst']??''):(string)$mailbox;
        $started=strtotime((string)($call['calldate']??'')) ?: 0;
        if (!$started || !isset($inbox[$extension])) return false;
        $ended=$started+max(0,(int)($call['duration']??0));
        $number=preg_replace('/[^0-9]/','',(string)($call['src']??''));
        foreach ($inbox[$extension] as $msg) {
            $recorded=(int)($msg['time']??0);
            if ($recorded<$started-5 || $recorded>$ended+30) continue;
            $from=preg_replace('/[^0-9]/','',(string)($msg['number']??''));
            // A different known caller is never a match.
            if ($number!=='' && $from!=='' && $number!==$from) continue;
            return true;
        }
        return false;
    }

    public static function classifyMissedCallRows(array $rows, $now) {
        $calls=[];
        foreach ($rows as $row) {
            $linked=(string)($row['linkedid']??$row['uniqueid']??'');
            if ($linked==='') continue;
            $calls[$linked][]=$row;
        }
        $found=[];
        foreach ($calls as $linked=>$group) {
            $targets=[]; $lastEnd=0;
            foreach ($group as $row) {
                $started=strtotime((string)($row['calldate']??'')) ?: 0;
                $lastEnd=max($lastEnd,$started+max(0,(int)($row['duration']??0)));
                // On FreePBX, outbound SCCP channels occur as Dial's
                // destination channel or in the Dial application arguments.
                // Other answered legs (VoiceMail, trunks, IVRs) do not count.
                $channels=[];
                $destination=(string)($row['dstchannel']??'');
                if (preg_match('~^SCCP/([0-9]{1,16})(?:[-/]|$)~i',$destination,$m))
                    $channels[]=$m[1];
                if (strcasecmp((string)($row['lastapp']??''),'Dial')===0 &&
                    preg_match_all('~SCCP/([0-9]{1,16})(?=[^0-9]|$)~i',(string)($row['lastdata']??''),$matches))
                    $channels=array_merge($channels,$matches[1]);
                foreach (array_unique($channels) as $ext) {
                    if (!isset($targets[$ext])) $targets[$ext]=['answered'=>false,'candidate'=>null];
                    if (strtoupper((string)($row['disposition']??''))==='ANSWERED' &&
                        preg_match('~^SCCP/'.preg_quote($ext,'~').'(?=[^0-9]|$)~i',$destination)) {
                        $targets[$ext]['answered']=true;
                    }
                    if ($targets[$ext]['candidate']===null ||
                        $started > (strtotime((string)$targets[$ext]['candidate']['calldate'])?:0))
                        $targets[$ext]['candidate']=$row;
                }
            }
            // Select the *calling* side of the Dial() operation, not the
            // SCCP destination leg. CDR rows may arrive newest-first.
            $origin='';
            $originClid='';
            foreach ($group as $entry) {
                $source=(string)($entry['src']??'');
                if (!preg_match('/^[+0-9*#]{1,32}$/D',$source)) continue;
                if (strcasecmp((string)($entry['lastapp']??''),'Dial')!==0) continue;
                if (!preg_match('~SCCP/[0-9]{1,16}(?=[^0-9]|$)~i',(string)($entry['lastdata']??''))) continue;
                $origin=$source;
                $originClid=(string)($entry['clid']??'');
                break;
            }
            if ($origin==='') {
                foreach ($group as $entry) {
                    $source=(string)($entry['src']??'');
                    if (!preg_match('/^[+0-9*#]{1,32}$/D',$source)) continue;
                    // Prefer original call legs, not an SCCP outgoing line.
                    if (stripos((string)($entry['lastdata']??''),'(Outgoing Line)')!==false) continue;
                    $origin=$source;
                    $originClid=(string)($entry['clid']??'');
                    break;
                }
            }
            // Wait until all CDR legs have finished. This avoids declaring
            // a call missed while a phone is still ringing or in voicemail.
            if ($lastEnd===0 || $lastEnd>$now-15 || $lastEnd<$now-240) continue;
            foreach ($targets as $ext=>$state) {
                if ($state['answered'] || !$state['candidate']) continue;
                $row=$state['candidate'];
                // Use CDR caller identity, not the originating SCCP channel
                // number: those can be different (e.g. 9111 presents 111).
                // Prefer the actual source CDR leg for linked calls.
                $row['src']=$origin;
                $sourceClid=trim($originClid);
                if ($sourceClid!=='') $row['clid']=$sourceClid;
                $row['dst']=$ext;
                $row['uniqueid']=$linked;
                $row['disposition']='NO ANSWER';
                $found[]=$row;
            }
        }
        return $found;
    }

}
