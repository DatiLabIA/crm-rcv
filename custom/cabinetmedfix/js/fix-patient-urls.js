/**
 * CabinetMedFix - URL Fix for Patient Links
 * 
 * This script automatically corrects URLs that point to /societe/card.php
 * to redirect to /custom/cabinetmed/card.php for patients with canvas=patient@cabinetmed
 * 
 * Part of the CabinetMedFix independent module
 * Survives all updates to Dolibarr and CabinetMed
 */

(function() {
'use strict';

/**
 * Fix all links on the page that point to patients
 */
function fixPatientLinks() {
// Find all links to societe/card.php
jQuery('a[href*="/societe/card.php"]').each(function() {
var $link = jQuery(this);
var href = $link.attr('href');

// Only fix if we're on a cabinetmed page (patients list, patient card, etc.)
if (window.location.pathname.indexOf('/cabinetmed/') !== -1) {
// Get the socid from the URL
var socidMatch = href.match(/[?&]socid=(\d+)/);
if (socidMatch) {
// Replace the URL
var newHref = href.replace('/societe/card.php', '/custom/cabinetmed/card.php');

// Add canvas parameter if not present
if (newHref.indexOf('canvas=') === -1) {
var separator = (newHref.indexOf('?') === -1) ? '?' : '&';
newHref += separator + 'canvas=patient@cabinetmed';
}

$link.attr('href', newHref);
$link.attr('data-fixed-by-cabinetmedfix', '1');
}
}
});
}

/**
 * Fix form action URLs
 */
function fixFormActions() {
// Find forms that might submit to wrong URL
jQuery('form[action*="/societe/card.php"]').each(function() {
var $form = jQuery(this);
var action = $form.attr('action');

// Check if canvas input exists and is patient
var canvasInput = $form.find('input[name="canvas"]');
if (canvasInput.length > 0 && canvasInput.val() === 'patient@cabinetmed') {
// Fix the form action
var newAction = action.replace('/societe/card.php', '/custom/cabinetmed/card.php');
$form.attr('action', newAction);
$form.attr('data-fixed-by-cabinetmedfix', '1');
}
});
}

/**
 * Intercept clicks on patient links
 */
function interceptPatientClicks() {
// Intercept clicks on patient links to ensure correct redirection
jQuery(document).on('click', 'a[href*="/societe/card.php"]', function(e) {
var href = jQuery(this).attr('href');

// Only intercept if canvas parameter is present
if (href.indexOf('canvas=patient@cabinetmed') !== -1) {
e.preventDefault();

// Fix URL and navigate
var newHref = href.replace('/societe/card.php', '/custom/cabinetmed/card.php');
window.location.href = newHref;
return false;
}

// Only intercept if we're on a cabinetmed page
if (window.location.pathname.indexOf('/cabinetmed/') !== -1) {
var socidMatch = href.match(/[?&]socid=(\d+)/);
if (socidMatch) {
e.preventDefault();

// Build corrected URL
var newHref = href.replace('/societe/card.php', '/custom/cabinetmed/card.php');
if (newHref.indexOf('canvas=') === -1) {
var separator = (newHref.indexOf('?') === -1) ? '?' : '&';
newHref += separator + 'canvas=patient@cabinetmed';
}

// Navigate to corrected URL
window.location.href = newHref;
return false;
}
}
});
}

/**
 * Fix the client field in edit forms
 */
function fixClientField() {
// Only on patient edit pages
if (window.location.pathname.indexOf('/cabinetmed/card.php') !== -1 && 
    window.location.search.indexOf('action=edit') !== -1) {

// Check if there's a hidden canvas field indicating this is a patient
var canvasField = jQuery('input[name="canvas"][value="patient@cabinetmed"]');
if (canvasField.length > 0) {
// Ensure there's a client field with value 3
var clientField = jQuery('input[name="client"]');
if (clientField.length === 0) {
// Add hidden field if it doesn't exist
canvasField.after('<input type="hidden" name="client" value="3" data-added-by-cabinetmedfix="1">');
} else if (clientField.val() == '0' || clientField.val() == '') {
// Fix the value if it's 0 or empty
clientField.val('3');
clientField.attr('data-fixed-by-cabinetmedfix', '1');
}
}
}
}

/**
 * Initialize all fixes
 */
function initFixes() {
fixPatientLinks();
fixFormActions();
fixClientField();
interceptPatientClicks();
}

// Execute fixes when DOM is ready
if (typeof jQuery !== 'undefined') {
jQuery(document).ready(function() {
initFixes();

// Re-run fixes after AJAX calls (for dynamic content)
if (typeof MutationObserver !== 'undefined') {
var observer = new MutationObserver(function(mutations) {
fixPatientLinks();
fixFormActions();
fixClientField();
});

observer.observe(document.body, {
childList: true,
subtree: true
});
}

console.log(' CabinetMedFix module loaded successfully');
});
} else {
console.error(' CabinetMedFix: jQuery not found');
}
})();
