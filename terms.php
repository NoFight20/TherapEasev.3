<?php
echo "
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Terms of Service and Agreement</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        h1, h2 {
            color:#4CAF50;
        }
        h1 {
            text-align: center;
            margin-bottom: 20px;
        }
        h2 {
            margin-top: 20px;
        }
        p {
            margin: 10px 0;
        }
        ul {
            margin: 10px 0 10px 20px;
            padding: 0;
            list-style-type: disc;
        }
        li {
            margin-bottom: 8px;
        }
        .highlight {
            color: #ff0000;
            font-weight: bold;
        }
        .contact {
            font-weight: bold;
        }
        .btn-container {
            text-align: center;
            margin-top: 20px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            font-size: 16px;
            color: #fff;
            background-color: #0056b3;
            text-decoration: none;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            transition: background-color 0.3s ease;
        }
        .btn:hover {
            background-color: #00408a;
        }
        footer {
            margin-top: 20px;
            text-align: center;
            font-size: 0.9em;
            color: #555;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Terms of Service and Agreement</h1>
        <p><strong>Last Updated:</strong> [Date]</p>

        <h2>1. Acceptance of Terms</h2>
        <p>Welcome to <strong>TherapEase</strong>. By accessing or using our system, you agree to be bound by these Terms of Service (\"Terms\"), including any additional terms, conditions, and policies referenced herein. If you do not agree to all the terms and conditions of this agreement, you may not access the system or use any services.</p>

        <h2>2. Changes to Terms</h2>
        <p>We reserve the right to update, change, or replace any part of these Terms by posting updates and changes to our website or system. It is your responsibility to check this page periodically for changes. Your continued use of or access to the system following the posting of any changes constitutes acceptance of those changes.</p>

        <h2>3. System Use and User Responsibility</h2>
        <p><strong>TherapEase</strong> is intended for authorized healthcare professionals, administrative staff, and patients. Users are responsible for maintaining the confidentiality of their login credentials and for all activities that occur under their account. You agree to notify us immediately of any unauthorized use of your account or any other breach of security.</p>

        <h2>4. Privacy and Data Protection</h2>
        <p>We are committed to protecting the privacy and confidentiality of patient information. Any personal data provided through the system is subject to our Privacy Policy. By using the system, you agree that we may process personal data in accordance with our Privacy Policy and applicable data protection regulations.</p>

        <h2>5. Prohibited Conduct</h2>
        <p>You agree not to use the system for any unlawful purpose or in violation of any applicable laws and regulations. Specifically, you agree not to:</p>
        <ul>
            <li>Share or disclose any patient information without proper authorization;</li>
            <li>Access or attempt to access any unauthorized areas of the system;</li>
            <li>Interfere with the proper working of the system, including introducing viruses or other harmful code;</li>
            <li>Engage in any activity that could compromise the security or integrity of the system.</li>
        </ul>

        <h2>6. Limitation of Liability</h2>
        <p>We do not guarantee that the system will be error-free or uninterrupted, and we are not responsible for any issues that may arise from the use of the system. In no event shall we be liable for any direct, indirect, incidental, special, or consequential damages arising out of or related to the use of the system.</p>

        <h2>7. Indemnification</h2>
        <p>You agree to indemnify, defend, and hold harmless <strong>TherapEase</strong>, its officers, directors, employees, and agents from and against any claims, damages, losses, liabilities, and expenses (including legal fees) arising out of or related to your use of the system, your violation of these Terms, or your infringement of any rights of another party.</p>

        <h2>8. Termination</h2>
        <p>We reserve the right to terminate or suspend access to the system at any time, without prior notice or liability, for any reason, including without limitation if you breach these Terms. Upon termination, your right to use the system will cease immediately.</p>

        <h2>9. Governing Law</h2>
        <p>These Terms shall be governed and construed in accordance with the laws of <span class='highlight'>[Your Jurisdiction]</span>, without regard to its conflict of law provisions.</p>

        <h2>10. Contact Information</h2>
        <p>If you have any questions about these Terms, please contact us at <span class='contact'>[Contact Information]</span>.</p>

        <h2>11. Entire Agreement</h2>
        <p>These Terms, together with any additional policies or guidelines we provide, constitute the entire agreement between you and us regarding your use of the system and supersede any prior agreements.</p>

        <div class='btn-container'>
            <a href='login.php' class='btn'>Go Back to Login</a>
        </div>
    </div>
    <footer>
        &copy; " . date("Y") . " TherapEase. All Rights Reserved.
    </footer>
</body>
</html>
";
?>
