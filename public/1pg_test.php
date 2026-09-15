<!DOCTYPE html>
<html>
<head>
    <title>Basic FPX Checkout</title>
</head>
<body>
    <h2>Enter Payment Details</h2>
    
    <!-- The form sends data to process.php when submitted -->
    <form action="1pg_process.php" method="POST">
        <label>Name:</label><br>
        <input type="text" name="name" required><br><br>

        <label>Phone:</label><br>
        <input type="text" name="phone" required><br><br>

        <label>Email:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Sale No (Order ID):</label><br>
        <input type="text" name="saleno" required><br><br>

        <label>Date:</label><br>
        <input type="date" name="date" required><br><br>

        <label>Amount (RM):</label><br>
        <input type="number" step="0.01" name="amount" required><br><br>

        <button type="submit">Pay with FPX</button>
    </form>
</body>
</html>
