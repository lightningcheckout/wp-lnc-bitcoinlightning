
<div style="text-align:center"><img src="<?php echo plugin_dir_url( __FILE__ ) . 'images/countdown.gif'; ?>" width="90"></div>
<div class="qr_invoice" id="qr_invoice">

	<a href="lightning:<?php echo esc_textarea($invoice) ?>"><div id="qrcode"></div></a>
	 <p><button onclick="copyToClipboard('#invoice')">Click to copy Invoice</button></p>
	 <div class="hidden_invoice" id="invoice"><?php echo esc_textarea($invoice) ?></div>
</div>
<div class="lightning_checkout">
    <small>Lightning Payment Processor</small><br>
     <a href="https://lightningcheckout.eu"><img src="<?php echo plugin_dir_url( __FILE__ ) . 'images/logo-lightningcheckout.png'; ?>"></a><br>
</div>
<script>
 // Initialize
 jQuery('#qrcode').qrcode("<?php echo esc_textarea($invoice) ?>");
</script>


<script type="text/javascript">
	var $ = jQuery;
	var check_payment_url = '<?php echo esc_url($check_payment_url) ?>';
	var order_id = <?php echo esc_attr($order_id) ?>;

	// Periodically check if the invoice got paid
	setInterval(function() {
		$.post(check_payment_url, {'order_id': order_id}).done(function(data) {
			var response = $.parseJSON(data);

			console.log(response);

			if (response['paid']) {
				window.location.replace(response['redirect']);
			}
		});

	}, 5000);

	// Copy into clipboard on click
	$('#qr_invoice').click(function() {
		$('#invoice_text').select();
		document.execCommand('copy');
	});
</script>

<script>
    function copyToClipboard(element) {
  var $temp = $("<input>");
  $("body").append($temp);
  $temp.val($(element).text()).select();
  document.execCommand("copy");
  $temp.remove();
}
</script>

<style>
	div.qr_invoice {
	 text-align:center
	}
	div.hidden_invoice {
	display: none;
    visibility: hidden;
}

small {
  font-size: smaller;
}
	div.lightning_checkout {
	 text-align:center;
	 padding: 20px;
	}
</style>