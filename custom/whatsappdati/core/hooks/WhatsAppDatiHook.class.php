<?php
/* Copyright (C) 2024 DatiLab
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       core/hooks/WhatsAppDatiHook.class.php
 * \ingroup    whatsappdati
 * \brief      Hook class for WhatsApp integration
 */

/**
 * Class for WhatsApp hooks
 */
class WhatsAppDatiHook
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var array Errors
	 */
	public $errors = array();

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook to add WhatsApp button in third party card
	 *
	 * @param array $parameters Hook parameters
	 * @param object $object Current object
	 * @param string $action Current action
	 * @return int <0 if KO, 0 if nothing done, >0 if OK
	 */
	public function doActions($parameters, &$object, &$action)
	{
		global $conf, $user, $langs;

		// Check if WhatsApp module is enabled
		if (empty($conf->whatsappdati->enabled)) {
			return 0;
		}

		return 0;
	}

	/**
	 * Hook to add WhatsApp button in third party/contact card
	 *
	 * @param array $parameters Hook parameters
	 * @param object $object Current object
	 * @return int <0 if KO, 0 if nothing done, >0 if OK
	 */
	public function formObjectOptions($parameters, &$object)
	{
		return 0;
	}

	/**
	 * Hook to add WhatsApp send section below the card
	 *
	 * @param array  $parameters Hook parameters
	 * @param object $object     Current object (Societe or Contact)
	 * @param string $action     Current action
	 * @return int               <0 if KO, 0 if nothing done, >0 if OK
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action)
	{
		global $conf, $user, $langs;

		// Check if WhatsApp module is enabled
		if (empty($conf->whatsappdati->enabled)) {
			return 0;
		}

		// Check permissions
		if (empty($user->rights->whatsappdati->message->send)) {
			return 0;
		}

		$contexts = explode(':', $parameters['context']);
		
		if (in_array('thirdpartycard', $contexts) || in_array('contactcard', $contexts)) {
			$langs->load("whatsappdati@whatsappdati");
			
			// Get phone number — try mobile first, then landline
			$phone = '';
			if (!empty($object->phone_mobile)) {
				$phone = $object->phone_mobile;
			} elseif (!empty($object->phone)) {
				$phone = $object->phone;
			}
			
			if (empty($phone)) {
				return 0;
			}

			// Contact name — contacts have firstname/lastname, thirdparties have name
			$contactName = '';
			if (!empty($object->firstname) || !empty($object->lastname)) {
				$contactName = trim($object->firstname.' '.$object->lastname);
			}
			if (empty($contactName) && !empty($object->name)) {
				$contactName = $object->name;
			}
			if (empty($contactName)) {
				$contactName = $phone;
			}

			// Determine fk_soc for conversation creation
			$fkSoc = 0;
			if (property_exists($object, 'socid') && $object->socid > 0) {
				$fkSoc = $object->socid;
			} elseif ($object->element === 'societe') {
				$fkSoc = $object->id;
			}
			
			// Load active lines
			require_once dol_buildpath('/whatsappdati/class/whatsappconfig.class.php', 0);
			$configObj = new WhatsAppConfig($this->db);
			$lines = $configObj->fetchActiveLines();
			if (empty($lines)) {
				return 0;
			}

			// Load templates (all approved across all lines)
			require_once dol_buildpath('/whatsappdati/class/whatsapptemplate.class.php', 0);
			$template = new WhatsAppTemplate($this->db);
			$templates = $template->fetchAll('approved');
			
			if (empty($templates)) {
				return 0;
			}
			
			// Build output
			$ajaxUrl = dol_buildpath('/custom/whatsappdati/ajax/template_detail.php', 1);
			$sendUrl = dol_buildpath('/custom/whatsappdati/ajax/send_message.php', 1);

			ob_start();
			?>
			<div class="fichecenter whatsapp-hook-section" style="margin-top:20px;">
			<div class="titre inline-block"><?php echo img_picto('', 'whatsappdati@whatsappdati', 'class="pictofixedwidth"'); ?><?php echo $langs->trans("SendWhatsAppMessage"); ?></div>
			<div style="margin-top:10px; padding:16px; background:var(--colorbacklinepair,#f8f9fa); border-radius:8px; border:1px solid var(--colorbacklinebreak,#e0e0e0);">

			<div id="whatsapp-hook-data" data-phone="<?php echo dol_escape_htmltag($phone); ?>" data-contact-name="<?php echo dol_escape_htmltag($contactName); ?>" data-fk-soc="<?php echo (int)$fkSoc; ?>">
			<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
			<?php
			// Line selector (only if multiple lines)
			if (count($lines) > 1) {
				echo '<select id="whatsapp-hook-line-select" class="flat minwidth150">';
				foreach ($lines as $line) {
					echo '<option value="'.(int) $line->rowid.'">'.dol_escape_htmltag($line->label ?: $line->phone_number_id).'</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="hidden" id="whatsapp-hook-line-select" value="'.(int) $lines[0]->rowid.'">';
			}
			?>
			<select id="whatsapp-hook-template-select" class="flat minwidth200">
			<option value=""><?php echo $langs->trans("SelectTemplate"); ?></option>
			<?php foreach ($templates as $tpl) { ?>
				<option value="<?php echo $tpl->rowid; ?>"><?php echo dol_escape_htmltag($tpl->name).' ('.$tpl->language.')'; ?></option>
			<?php } ?>
			</select>
			
			<button type="button" class="button" id="whatsapp-hook-preview-btn">
			<span class="fab fa-whatsapp" style="margin-right:4px;"></span><?php echo $langs->trans("SendWhatsApp"); ?>
			</button>
			</div>
			</div>
			
			<div id="whatsapp-hook-preview-area" style="display:none; margin-top:12px;">
			<div style="padding:12px; background:#fff; border-radius:8px; border:1px solid #e0e0e0;">
			<div style="font-size:12px; font-weight:600; color:#888; margin-bottom:6px;"><?php echo $langs->trans("PreviewTemplate"); ?></div>
			<div class="whatsapp-hook-preview-body" id="whatsapp-hook-preview-body" style="white-space:pre-wrap; font-size:13px;"></div>
			</div>
			<div id="whatsapp-hook-variables" style="margin-top:10px;"></div>

			<!-- Header image upload (shown dynamically by JS when applicable) -->
			<div id="whatsapp-hook-header-upload" style="display:none; margin-top:10px; padding:10px; background:#f0f9ff; border:1px dashed #7dd3fc; border-radius:8px;">
			<label style="display:block; font-size:12px; font-weight:600; color:#0369a1; margin-bottom:6px;">📷 <?php echo $langs->trans("HeaderImage"); ?></label>
			<input type="file" id="whatsapp-hook-header-image" accept="image/*" style="font-size:12px;" />
			</div>

			<div style="margin-top:12px; display:flex; gap:8px;">
			<button type="button" class="button" id="whatsapp-hook-send-btn"><span class="fas fa-paper-plane" style="margin-right:4px;"></span><?php echo $langs->trans("Send"); ?></button>
			<button type="button" class="button" id="whatsapp-hook-cancel-btn"><?php echo $langs->trans("Cancel"); ?></button>
			</div>
			<div id="whatsapp-hook-status" style="display:none; margin-top:8px; padding:8px 12px; border-radius:4px;"></div>
			</div>

			</div>
			</div>
			<?php
			?>
			<script type="text/javascript">
			$(document).ready(function() {
				var hookTemplateData = null;

				$("#whatsapp-hook-preview-btn").on("click", function() {
					var templateId = $("#whatsapp-hook-template-select").val();
					if (!templateId) {
						alert("<?php echo dol_escape_js($langs->trans("ErrorTemplateRequired")); ?>");
						return;
					}
					var $btn = $(this);
					$btn.prop("disabled", true);
					$.ajax({
						url: "<?php echo dol_escape_js($ajaxUrl); ?>",
						method: "GET",
						data: { id: templateId },
						dataType: "json",
						success: function(data) {
							if (data.success) {
								hookTemplateData = data.template;
								$("#whatsapp-hook-preview-body").text(hookTemplateData.body_text || "");
								var vars = hookTemplateData.variables || [];
								var varMapping = hookTemplateData.variable_mapping || {};
								var hookData = $("#whatsapp-hook-data");
								var contactName = hookData.data("contact-name") || "";
								var today = new Date().toLocaleDateString();
								var html = "";
								if (vars.length > 0) {
									for (var i = 0; i < vars.length; i++) {
										var varNum = vars[i];
										var cfg = varMapping[varNum] || { type: "free_text", label: "", default_value: "" };
										var autoValue = "";
										var isAutoResolved = false;
										var sourceLabel = cfg.label || "";

										switch (cfg.type) {
											case "contact_name":
												autoValue = contactName;
												isAutoResolved = !!autoValue;
												if (!sourceLabel) sourceLabel = "<?php echo dol_escape_js($langs->trans("VarTypeContactName")); ?>";
												break;
											case "operator_name":
												autoValue = "<?php echo dol_escape_js($user->getFullName($langs)); ?>";
												isAutoResolved = !!autoValue;
												if (!sourceLabel) sourceLabel = "<?php echo dol_escape_js($langs->trans("VarTypeOperatorName")); ?>";
												break;
											case "date_today":
												autoValue = today;
												isAutoResolved = true;
												if (!sourceLabel) sourceLabel = "<?php echo dol_escape_js($langs->trans("VarTypeDateToday")); ?>";
												break;
											case "fixed_text":
												autoValue = cfg.default_value || "";
												isAutoResolved = !!autoValue;
												if (!sourceLabel) sourceLabel = "<?php echo dol_escape_js($langs->trans("VarTypeFixedText")); ?>";
												break;
											default:
												autoValue = cfg.default_value || "";
												if (!sourceLabel) sourceLabel = "<?php echo dol_escape_js($langs->trans("VarTypeFreeText")); ?>";
												break;
										}

										html += '<div style="margin-bottom:6px;">';
										html += '<label style="font-size:12px;font-weight:600;display:block;">{{' + varNum + '}} <span style="font-weight:normal;color:#888;">(' + $("<span>").text(sourceLabel).html() + ')</span></label>';
										if (isAutoResolved && cfg.type !== "free_text" && cfg.type !== "url") {
											html += '<input type="text" class="flat whatsapp-hook-var-input minwidth200" data-var-num="' + varNum + '" value="' + $("<span>").text(autoValue).html() + '" readonly style="background:#f0fdf4;border-color:#86efac;color:#166534;" />';
											html += ' <span style="font-size:11px;color:#16a34a;">✓ Auto</span>';
										} else {
											html += '<input type="text" class="flat whatsapp-hook-var-input minwidth200" data-var-num="' + varNum + '" value="' + $("<span>").text(autoValue).html() + '" placeholder="<?php echo dol_escape_js($langs->trans("TemplateVariables")); ?> ' + varNum + '" />';
										}
										html += '</div>';
									}
								}
								$("#whatsapp-hook-variables").html(html);

								// Show header image upload if applicable
								if (hookTemplateData.header_type === "IMAGE" && hookTemplateData.header_image_mode === "on_send") {
									$("#whatsapp-hook-header-upload").show();
								} else {
									$("#whatsapp-hook-header-upload").hide();
								}

								$("#whatsapp-hook-preview-area").slideDown(200);
								$("#whatsapp-hook-status").hide();
								
								$(document).off("input.hookvars").on("input.hookvars", ".whatsapp-hook-var-input", function() {
									var body = hookTemplateData.body_text || "";
									$(".whatsapp-hook-var-input").each(function() {
										var num = $(this).data("var-num");
										var val = $(this).val();
										if (val) {
											body = body.replace(new RegExp("\\{\\{" + num + "\\}\\}", "g"), val);
										}
									});
									$("#whatsapp-hook-preview-body").text(body);
								});
							} else {
								alert("Error: " + data.error);
							}
						},
						complete: function() {
							$btn.prop("disabled", false);
						}
					});
				});

				$("#whatsapp-hook-cancel-btn").on("click", function() {
					$("#whatsapp-hook-preview-area").slideUp(200);
					hookTemplateData = null;
				});

				$("#whatsapp-hook-send-btn").on("click", function() {
					if (!hookTemplateData) return;
					var params = [];
					var valid = true;
					$(".whatsapp-hook-var-input").each(function() {
						var val = $(this).val().trim();
						if (!val) {
							$(this).css("border-color", "#dc3545");
							valid = false;
						} else {
							$(this).css("border-color", "");
						}
						params.push(val);
					});
					if (!valid) return;

					var $data = $("#whatsapp-hook-data");
					var phone = $data.data("phone");
					var contactName = $data.data("contact-name");
					var fkSoc = $data.data("fk-soc");
					var lineId = $("#whatsapp-hook-line-select").val();
					var templateId = $("#whatsapp-hook-template-select").val();

					var $btn = $(this);
					$btn.prop("disabled", true).text("<?php echo dol_escape_js($langs->trans("Sending")); ?>");

					// Check for header image upload
					var headerFile = null;
					var fileInput = document.getElementById("whatsapp-hook-header-image");
					if (fileInput && fileInput.files && fileInput.files.length > 0) {
						headerFile = fileInput.files[0];
					}

					var ajaxOpts;
					if (headerFile) {
						var formData = new FormData();
						formData.append("phone", phone);
						formData.append("contact_name", contactName);
						formData.append("fk_soc", fkSoc);
						formData.append("line_id", lineId);
						formData.append("template_id", templateId);
						formData.append("template_params", JSON.stringify(params));
						formData.append("token", $("input[name=token]").val());
						formData.append("header_image", headerFile);
						ajaxOpts = {
							url: "<?php echo dol_escape_js($sendUrl); ?>",
							method: "POST",
							data: formData,
							processData: false,
							contentType: false,
							dataType: "json"
						};
					} else {
						ajaxOpts = {
							url: "<?php echo dol_escape_js($sendUrl); ?>",
							method: "POST",
							data: {
								phone: phone,
								contact_name: contactName,
								fk_soc: fkSoc,
								line_id: lineId,
								template_id: templateId,
								template_params: JSON.stringify(params),
								token: $("input[name=token]").val()
							},
							dataType: "json"
						};
					}

					ajaxOpts.success = function(response) {
						if (response.success) {
							$("#whatsapp-hook-status").css({background: "#d4edda", color: "#155724", border: "1px solid #c3e6cb"})
								.html('<span class="fas fa-check" style="margin-right:4px;"></span><?php echo dol_escape_js($langs->trans("MessageSent")); ?>').show();
							hookTemplateData = null;
							$(".whatsapp-hook-var-input").val("");
							setTimeout(function() {
								$("#whatsapp-hook-preview-area").slideUp(200);
							}, 3000);
						} else {
							$("#whatsapp-hook-status").css({background: "#f8d7da", color: "#721c24", border: "1px solid #f5c6cb"})
								.text("<?php echo dol_escape_js($langs->trans("MessageFailed")); ?> " + (response.error || "")).show();
						}
					};
					ajaxOpts.error = function() {
						$("#whatsapp-hook-status").css({background: "#f8d7da", color: "#721c24", border: "1px solid #f5c6cb"})
							.text("<?php echo dol_escape_js($langs->trans("MessageFailed")); ?>").show();
					};
					ajaxOpts.complete = function() {
						$btn.prop("disabled", false).html('<span class="fas fa-paper-plane" style="margin-right:4px;"></span><?php echo dol_escape_js($langs->trans("Send")); ?>');
					};

					$.ajax(ajaxOpts);
				});

				$("#whatsapp-hook-template-select").on("change", function() {
					$("#whatsapp-hook-preview-area").slideUp(200);
					hookTemplateData = null;
				});
			});
			</script>
			<?php
			$out = ob_get_clean();
			$this->resprints = $out;
			return 1;
		}

		return 0;
	}

	/**
	 * Hook to inject the global floating WhatsApp chat widget on every page.
	 *
	 * Only rendered when:
	 *  - the module is enabled
	 *  - the user has conversation→read permission
	 *  - we are NOT already on the dedicated conversations page
	 *
	 * @param  array  $parameters  Hook parameters
	 * @param  object $object      Current object (unused)
	 * @param  string $action      Current action (unused)
	 * @return int                 0 = nothing done, 1 = HTML injected
	 */
	public function printCommonFooter($parameters, &$object, &$action)
	{
		global $conf, $user, $langs;

		// DEBUG: Verify hook is called
		error_log('[WhatsApp Widget] printCommonFooter hook called');

		// --- Guards -------------------------------------------------------
		if (empty($conf->whatsappdati->enabled)) {
			error_log('[WhatsApp Widget] Module not enabled');
			return 0;
		}
		if (empty($user->rights->whatsappdati->conversation->read)) {
			error_log('[WhatsApp Widget] User lacks read permission');
			return 0;
		}

		// Skip on the full-size conversations page and module-internal pages
		$scriptFile = !empty($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : (!empty($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');
		$script = basename($scriptFile);
		$scriptDir = basename(dirname($scriptFile));

		// Skip conversations.php (full chat page) — widget would be redundant
		if ($script === 'conversations.php' && $scriptDir === 'whatsappdati') {
			error_log('[WhatsApp Widget] Skipped: on conversations page');
			return 0;
		}

		error_log('[WhatsApp Widget] Passed all guards, rendering widget');

		$langs->load('whatsappdati@whatsappdati');

		// --- Paths --------------------------------------------------------
		$moduleUrl  = dol_buildpath('/whatsappdati', 1);
		$ajaxUrl    = $moduleUrl.'/ajax/widget.php';
		$convPageUrl = $moduleUrl.'/conversations.php';
		$jsUrl      = $moduleUrl.'/js/whatsapp_widget.js';

		// Cache buster: use file modification time if available
		$jsFilePath = dol_buildpath('/whatsappdati/js/whatsapp_widget.js', 0);
		$jsVersion  = @filemtime($jsFilePath);
		if (empty($jsVersion)) {
			$jsVersion = '20260226'; // Fallback
		}

		// --- Build label map for JS --------------------------------------
		$labelKeys = array(
			'WhatsAppChats',
			'OpenFull',
			'Close',
			'Loading',
			'SearchConversations',
			'NoConversations',
			'SoundEnabled',
			'NewMessage',
			'NewWhatsApp',
			'UnreadMessages',
			'NoMessages',
			'TypeMessage',
			'Send',
			'WindowExpired',
			'ErrorSending',
			'JustNow',
			'Document',
		);
		$labels = array();
		foreach ($labelKeys as $k) {
			$labels[$k] = $langs->trans($k);
		}
		$labelsJson = json_encode($labels, JSON_HEX_TAG | JSON_HEX_APOS);
		$token      = newToken();

		// --- Build output via resprints (standard Dolibarr pattern) ------
		$out = '';
		$out .= "\n".'<!-- WhatsApp Floating Widget -->';
		$out .= "\n".'<script>';
		$out .= 'window.WhatsAppWidgetBase   = '.json_encode($ajaxUrl).';';
		$out .= 'window.WhatsAppWidgetToken  = '.json_encode($token).';';
		$out .= 'window.WhatsAppConvPageUrl  = '.json_encode($convPageUrl).';';
		$out .= 'window.WhatsAppWidgetLabels = '.$labelsJson.';';
		$out .= 'console.log("[WhatsApp Widget] Hook OK, vars injected");';
		$out .= '</script>';
		$out .= "\n".'<script src="'.$jsUrl.'?v='.$jsVersion.'"></script>';
		$out .= "\n".'<!-- /WhatsApp Floating Widget -->';

		$this->resprints = $out;

		return 0;
	}
}
