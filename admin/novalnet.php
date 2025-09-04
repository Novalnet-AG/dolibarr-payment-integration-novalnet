<?php
/**
 * Novalnet payment extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Novalnet End User License Agreement
 * that is bundled with this package in the file LICENSE.txt
 *
 * DISCLAIMER
 *
 * If you wish to customize Novalnet payment extension for your needs,
 * please contact technic@novalnet.de for more information.
 *
 * @category   Novalnet
 * @package    Novalnet_Payment
 * @copyright  Copyright (c) Novalnet AG
 * @license    https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
require_once '../lib/novalnet.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';


// Translations
$langs->loadLangs(array('admin', 'other', 'NOVALNET','errors', 'admin', 'novalnet@novalnet'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */


if ($action == 'setvalue' && $user->admin) {

	$result = dolibarr_set_const($db, "NOVALNET_PRODUCT_ACTIVATION", GETPOST('NOVALNET_PRODUCT_ACTIVATION', 'alpha'), 'chaine', 0, '', $conf->entity);
	if (!($result > 0)) {
		$error++;
	}
	$result = dolibarr_set_const($db, "NOVALNET_PAYMENT_KEY", GETPOST('NOVALNET_PAYMENT_KEY', 'alpha'), 'chaine', 0, '', $conf->entity);
	if (!($result > 0)) {
		$error++;
	}
	$result = dolibarr_set_const($db, "ONLINE_PAYMENT_CREDITOR", GETPOST('ONLINE_PAYMENT_CREDITOR', 'alpha'), 'chaine', 0, '', $conf->entity);
	if (!($result > 0)) {
		$error++;
	}
	$result = dolibarr_set_const($db, "NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS", GETPOST('NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS', 'int'), 'chaine', 0, '', $conf->entity);
	if (!($result > 0)) {
		$error++;
	}
	$result = dolibarr_set_const($db, "NOVALNET_WEBHOOK_EMAIL", GETPOST('NOVALNET_WEBHOOK_EMAIL', 'alpha'), 'chaine', 0, '', $conf->entity);
	if (!($result > 0)) {
		$error++;
	}
	$result = dolibarr_set_const($db, "NOVALNET_ONLINE_PAYMENT_SENDEMAIL", GETPOST('NOVALNET_ONLINE_PAYMENT_SENDEMAIL', 'alpha'), 'chaine', 0, '', $conf->entity);
	if (!($result > 0)) {
		$error++;
	}
	$result = dolibarr_set_const($db, "NOVALNET_TEST_WEBHOOK_VALUE", GETPOST('NOVALNET_TEST_WEBHOOK_VALUE', 'alpha'), 'chaine', 0, '', $conf->entity);
	if (!($result > 0)) {
		$error++;
	}
	 $selectedTariff = GETPOST('NOVALNET_TARRIFF', 'alpha'); 

    if (!empty($selectedTariff)) {
        $result = dolibarr_set_const($db, "NOVALNET_TARRIFF", $selectedTariff, 'chaine', 0, '', $conf->entity);
        if (!($result > 0)) {
            $error++;
        }
	} 
	
if (!$error) {
	$db->commit();
	setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
} else {
	$db->rollback();
	dol_print_error($db);
}

}



/*
 * View
 */

 $form = new Form($db);

 $help_url = '';
 $page_name = $langs->trans("NOVALNET_MODULE_SETUP");
 
 llxHeader('', $langs->trans($page_name));

 // Subheader
 $linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1'.'">'.$langs->trans("BackToModuleList").'</a>';
 
 print load_fiche_titre($langs->trans("ModuleSetup").'Novalnet', $linkback);
 // Configuration header
 $head = novalnetAdminPrepareHead();
 
 print '<form method="post" action="'.$_SERVER["PHP_SELF"].'">';
 print '<input type="hidden" name="token" value="'.newToken().'">';
 print '<input type="hidden" name="action" value="setvalue">';
 
 
 print dol_get_fiche_head($head, 'novalnetaccount', '', -1);
 
 print '<span class="opacitymedium">'.$langs->trans("NovalnetDescription")."</span><br>\n";
 
 
 // Test if php curl exist
 if (!function_exists('curl_version')) {
	 $langs->load("errors");
	 setEventMessages($langs->trans("ErrorPhpCurlNotInstalled"), null, 'errors');
 }
 
 
 print '<br>';
 
 print '<div class="div-table-responsive-no-min">';
 print '<table class="noborder centpercent">';
 
 // Account Parameters
 print '<tr class="liste_titre">';
 print '<td>'.$langs->trans("NovalnetAccountParameter").'</td>';
 print '<td>'.$langs->trans("Value").'</td>';
 print "</tr>\n";
 
 print '<tr class="oddeven"><td class="fieldrequired">';
 print $langs->trans("NOVALNET_PRODUCT_ACTIVATION").'</td><td>';
 print '<input size="32" type="text" name="NOVALNET_PRODUCT_ACTIVATION" id="NOVALNET_PRODUCT_ACTIVATION_KEY" value="'.getDolGlobalString('NOVALNET_PRODUCT_ACTIVATION').'" required>';
 print '</td></tr>';
 
 
 print '<tr class="oddeven"><td class="fieldrequired">';
 print $langs->trans("NOVALNET_PAYMENT_KEY").'</td><td>';
 print '<input size="32" type="text" name="NOVALNET_PAYMENT_KEY" id="NOVALNET_PAYMENT_ACCESS_KEY" value="'.getDolGlobalString('NOVALNET_PAYMENT_KEY').'" required>';
 print '</td></tr>';
 $existingTariff = getDolGlobalString('NOVALNET_TARRIFF');
 print '<tr class="oddeven"><td>';
 print $langs->trans("NOVALNET_TARRIFF").'</td><td>';
 print '<select name="NOVALNET_TARRIFF" id="NOVALNET_TARRIFF">';
 print '<option value="">Select Tariff</option>'; // Default option
 
 if (isset($tariffs) && is_array($tariffs)) {
	 foreach ($tariffs as $id => $tariff) {
		 $selected = ($existingTariff == $id) ? $existingTariff : ''; // Check if it's the selected value
		 print '<option value="'.htmlspecialchars($id).'" '.$selected.'>'.htmlspecialchars($tariff['name']).'</option>';
	 }
 }

 print '</select>'; // Populate the tariff select
 print '<input type="hidden" value="'.getDolGlobalString('NOVALNET_TARRIFF').'" id="selected_tariff"';
 print '</td></tr>';

 print '</table>';
 print '</div>';

 print '<div class="div-table-responsive-no-min">';
 print '<table class="noborder centpercent">';
 
 // Webhook Parameters
 print '<tr class="liste_titre">';
 print '<td>'.$langs->trans("NovalnetWebhookParameter").'</td>';
 print '<td>'.$langs->trans("Value").'</td>';
 print "</tr>\n";

print '<tr class="oddeven"><td>';
print $langs->trans("NOVALNET_WEBHOOK_URL").'</td><td>';
print '<input size="64" class="minwidth200" type="text" name="WEBHOOK_URL" id="WEBHOOK_URL" value="'.DOL_MAIN_URL_ROOT.'/custom/novalnet/class/NovalnetCallback.php">';
print '</td></tr>';

print '<tr class="oddeven"><td>';
print '</td><td>';
print '<button type="button" id="configure_webhook_url" class="button">'.$langs->trans("Configure").'</button>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>';
print $langs->trans("NOVALNET_TEST_WEBHOOK") . '</td><td>';
$testWebhookValue = getDolGlobalString('NOVALNET_TEST_WEBHOOK_VALUE');  // Get current value
print '<select name="NOVALNET_TEST_WEBHOOK_VALUE">';
print '<option value="0" ' . ($testWebhookValue == 0 ? 'selected' : '') . '>No</option>';
print '<option value="1" ' . ($testWebhookValue == 1 ? 'selected' : '') . '>Yes</option>';
print '</select>';
print '</td></tr>';




print '<tr class="oddeven"><td>';
print $langs->trans("NOVALNET_WEBHOOK_EMAIL").'</td><td>';
print '<input class="minwidth200" type="text" name="NOVALNET_WEBHOOK_EMAIL" value="'.getDolGlobalString('NOVALNET_WEBHOOK_EMAIL').'">';
print ' &nbsp;  <span class="opacitymedium">'.$langs->trans("Example").': myemail@myserver.com</span>';
print '</td></tr>';


print '</table>';
print '</div>';


print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';


print '<br><br>';

// Usage Parameters
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("UsageParameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print "</tr>\n";

print '<tr class="oddeven"><td>';
print $langs->trans("PublicVendorName").'</td><td>';
print '<input size="20" type="text" name="ONLINE_PAYMENT_CREDITOR" value="'.getDolGlobalString('ONLINE_PAYMENT_CREDITOR').'">';
print '</td></tr>';

if (isModEnabled("banque")) {
	print '<tr class="oddeven"><td>';
	print $langs->trans("BankAccount").'</td><td>';
	print img_picto('', 'bank_account').' ';
	$form->select_comptes(getDolGlobalString('NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS'), 'NOVALNET_BANK_ACCOUNT_FOR_PAYMENTS', 0, '', 1);
	print '</td></tr>';
}

print '<tr class="oddeven"><td>';
print $langs->trans("NOVALNET_ONLINE_PAYMENT_SENDEMAIL").'</td><td>';
print '<input size="40" class="minwidth200" type="text" name="NOVALNET_ONLINE_PAYMENT_SENDEMAIL" value="'.getDolGlobalString('NOVALNET_ONLINE_PAYMENT_SENDEMAIL').'">';
print ' &nbsp;  <span class="opacitymedium">'.$langs->trans("Example").': myemail@myserver.com</span>';
print '</td></tr>';

print '</table>';
print '</div>';

print '<br>';


print $form->buttonsSaveCancel("Modify", '');
print '</form>';

$currentToken = currentToken();

print '<script type="text/javascript">
$(document).ready(function() {
	
	send_api_request();
	$("#NOVALNET_PRODUCT_ACTIVATION_KEY, #NOVALNET_PAYMENT_ACCESS_KEY").on("change", function(e) {
		send_api_request();
	});
	
	function send_api_request() {
		var product_activation_key = $("#NOVALNET_PRODUCT_ACTIVATION_KEY").val();
        var payment_access_key = $("#NOVALNET_PAYMENT_ACCESS_KEY").val();
        
        if($.trim(product_activation_key) != "" && $.trim(payment_access_key) != "") {
			$.ajax({
				method: "POST",
				url: "'.DOL_URL_ROOT.'/custom/novalnet/ajax/novalnet_ajax_handler.php",
				data: { token: "'. $currentToken .'", product_activation_key:  product_activation_key, payment_access_key: payment_access_key, request_type: "get_merchant_details"},
				dataType: "json",
				error: function (jqXHR, textStatus, errorThrown) {
					alert(textStatus);
				},
				success: function (data) {
					if(data.error_msg) {
						alert(data.error_msg);
					}
					else {
						var tariffDropdown = $("#NOVALNET_TARRIFF");
						$.each(data.merchant.tariff, function ( key, value ) {
							tariffDropdown.append($("<option>").attr("value", key).text(value.name));
						});
						
						var selected_tariff = $("#selected_tariff").val();
						if(selected_tariff) {
							$("#NOVALNET_TARRIFF option[value="+selected_tariff+"]").attr("selected", "selected");
						}
						
						if(data.merchant.test_mode == 1) {
							$("#test_mode_desc").show();
						}
					}
					
				}
			});
		}
	}
		$("#configure_webhook_url").on("click", function() {
        // Get the value of the WEBHOOK_URL input field
        var webhookUrl = $("#WEBHOOK_URL").val();
        var product_activation_key = $("#NOVALNET_PRODUCT_ACTIVATION_KEY").val(); // You can dynamically generate this if needed
        
        // Send the AJAX request
        $.ajax({
            method: "POST",
            url: "'.DOL_URL_ROOT.'/custom/novalnet/ajax/novalnet_ajax_handler.php",
            data: { 
                token: "' . $currentToken . '", 
                webhook_url: webhookUrl, 
                product_activation_key: product_activation_key, 
                request_type: "configure_webhook_url"
            },
            dataType: "json",
            error: function (jqXHR, textStatus, errorThrown) {
                alert(textStatus);
            },
            success: function (data) {
                if (data.error_msg) {
                    alert(data.error_msg);
                } else {
                    // Handle success - you can show a success message, for example
                    alert("Webhook URL configured successfully.");
                }
            }
        });
    });
});
</script>
';

//include DOL_DOCUMENT_ROOT.'/core/tpl/onlinepaymentlinks.tpl.php';
 
 // Page end
 print dol_get_fiche_end();
 
 
 llxFooter();
 $db->close();


