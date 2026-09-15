<?php
// Check if the form was actually submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. Capture the data from index.php
    $name   = $_POST['name'];
    $phone  = $_POST['phone'];
    $email  = $_POST['email'];
    $saleno = $_POST['saleno'];
    $date   = $_POST['date'];
    $amount = $_POST['amount'];

    // 2. Gateway Settings (Replace these with your actual gateway details)
    $gateway_url = "https://example-fpx-gateway.com/pay"; 
    $merchant_id = "YOUR_MERCHANT_ID";
    $secret_key  = "YOUR_SECRET_KEY";

    // 3. Security Signature (Most gateways require a hashed string to prevent tampering)
    // Example: $signature = hash('sha256', $merchant_id . $saleno . $amount . $secret_key);
    
    // 4. Create an invisible form with the data and auto-submit it to the Gateway
    echo "Processing your payment, please wait...";
    ?>
    
    <form id="fpxForm" action="<?php echo $gateway_url; ?>" method="POST" style="display:none;">
        <input type="hidden" name="merchant_id" value="<?php echo $merchant_id; ?>">
        <input type="hidden" name="order_id"    value="<?php echo $saleno; ?>">
        <input type="hidden" name="amount"      value="<?php echo $amount; ?>">
        <input type="hidden" name="name"        value="<?php echo $name; ?>">
        <input type="hidden" name="email"       value="<?php echo $email; ?>">
        <input type="hidden" name="phone"       value="<?php echo $phone; ?>">
        <!-- <input type="hidden" name="signature" value="<?php echo $signature; ?>"> -->
    </form>

    <!-- JavaScript to automatically click submit on the hidden form -->
    <script>
        document.getElementById("fpxForm").submit();
    </script>
    
    <?php
} else {
    echo "Invalid request. Please go back to the form.";
}
?>
