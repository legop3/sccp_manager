<?php
// Validate the actual Cisco 7945G template, not an artificial fixture.
require_once __DIR__ . '/../sccpManTraits/helperFunctions.php';
require_once __DIR__ . '/../sccpManClasses/xmlinterface.class.php';
use FreePBX\modules\Sccp_manager\xmlinterface;

$path = __DIR__ . '/../conf/SEP0000000000.cnf.xml_79df_template';
$phone = simplexml_load_file($path);
if ($phone === false) throw new RuntimeException('7945G XML template is invalid');
if (isset($phone->commonProfile)) throw new RuntimeException('Expected 79df legacy template without commonProfile');

xmlinterface::ensureBackgroundProfile($phone, true);
if ((string) $phone->commonProfile->backgroundImageAccess !== 'true') {
    throw new RuntimeException('Backgrounds were not enabled');
}
$xml = $phone->asXML();
if (!(strpos($xml, '<commonProfile>') < strpos($xml, '<vendorConfig>'))) {
    throw new RuntimeException('commonProfile was inserted in the wrong XML order');
}
xmlinterface::ensureBackgroundProfile($phone, false);
if ((string) $phone->commonProfile->backgroundImageAccess !== 'false') {
    throw new RuntimeException('Background disabling not written');
}
if (substr_count($phone->asXML(), '<commonProfile>') !== 1) {
    throw new RuntimeException('Duplicated commonProfile');
}
echo "7945G wallpaper configuration tests passed.\n";
