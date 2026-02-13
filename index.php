<?php
// landing.php
include("connection.php");

// Default background image 
$defaultBackground = "img/GentriMed.jpg";

// Try to get the landing page background URL from Firebase Database
$backgroundData = $database->getReference("backgrounds/landing")->getValue();

if ($backgroundData && isset($backgroundData['url'])) {
    $backgroundURL = $backgroundData['url'];
} else {
    $backgroundURL = $defaultBackground;
}

// Default logo image
$defaultLogo = "img/default_logo.png";

// LOGO
$logosHistory = $database->getReference("site/logos_history")->getValue();

if ($logosHistory && is_array($logosHistory)) {
    $lastLogoEntry = end($logosHistory); 
    if (isset($lastLogoEntry['url'])) {
        $logoURL = $lastLogoEntry['url'];
    } else {
        $logoURL = $defaultLogo;
    }
} else {
    $logoURL = $defaultLogo;
}

/* ====== NEW: Mission, Vision, Services  ====== */
$mission = $database->getReference("site/home/mission")->getValue();
$vision  = $database->getReference("site/home/vision")->getValue();
$servicesData = $database->getReference("site/services")->getValue();

if (!$mission) {
    $mission = "To deliver compassionate, patient-centered care through safe, timely, and evidence-based services for our community.";
}
if (!$vision) {
    $vision = "To be Cavite’s most trusted hospital—recognized for clinical excellence, innovation, and a culture of care.";
}

// Fetch services and specialties
$servicesData = $database->getReference("site/home/services")->getValue();
$specialtiesData = $database->getReference("specialties")->getValue();

// Normalize services
$services = [];
if (is_array($servicesData)) {
    foreach ($servicesData as $item) {
        if (is_array($item)) {
            $name = isset($item['name']) ? trim($item['name']) : '';
            $desc = isset($item['desc']) ? trim($item['desc']) : '';
            if ($name !== '') {
                $services[] = $desc !== '' ? ($name . " – " . $desc) : $name;
            }
        } else {
            $str = trim((string)$item);
            if ($str !== '') $services[] = $str;
        }
    }
} elseif (is_string($servicesData) && trim($servicesData) !== '') {
    $services = array_map('trim', explode(',', $servicesData));
}

// Append specialties if available
if (is_array($specialtiesData)) {
    foreach ($specialtiesData as $spec) {
        if (isset($spec['sname']) && trim($spec['sname']) !== '') {
            $services[] = trim($spec['sname']);
        }
    }
}

// Fallback services if none found
if (empty($services)) {
    $services = [
        "Outpatient & Specialist Clinics",
        "Emergency & Trauma Care",
        "Laboratory & Diagnostics",
        "Imaging (X-ray, Ultrasound, CT)",
        "Inpatient Care & Surgery",
        "Pharmacy Services",
        "Rehabilitation & Physical Therapy"
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/animations.css">  
    <link rel="stylesheet" href="css/main.css">  
    <link rel="stylesheet" href="css/index.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <title>TherapEase</title>
    <style>
  :root{
    --card-bg: rgba(255,255,255,.9);
    --stroke: rgba(17, 75, 95, .12);
    --ink: #0f172a;       /* text */
    --accent:rgba(17, 95, 21, 0.9);    /* headings */
  }

  html, body { min-height: 100vh; }

  body {
    background-image: url('<?php echo $backgroundURL; ?>');
    background-repeat: no-repeat;
    background-attachment: fixed;
    background-size: cover;
    position: relative;
  }

  /* Subtle top gradient so hero text remains readable on bright photos */
  .bg-gradient {
    position: fixed;
    inset: 0;
    pointer-events: none;
    background: linear-gradient(
      to bottom,
      rgba(0,0,0,.35) 0%,
      rgba(0,0,0,.18) 20%,
      rgba(0,0,0,0) 45%
    );
    z-index: 0;
  }

  table { animation: transitionIn-Y-bottom .5s; position: relative; z-index: 1; }

  /* Optional: Style the logo */
  .logo-img {
    vertical-align: middle;
    max-height: 80px;
    margin-left: 20px;
    border-radius: 50%;
  }

  /* Make top hero text readable on bright backgrounds */
  .heading-text, .sub-text2 { text-shadow: 0 2px 8px rgba(0,0,0,.35); }

  /* ===== REFINED: Mission / Vision / Services ===== */
.content-wrap {
  display: flex;
  justify-content: center;
  padding-inline: clamp(12px, 3vw, 24px);
  margin-top: clamp(80px, 38vh, 320px);
  margin-bottom: clamp(40px, 8vh, 120px);
  position: relative;
  z-index: 1;
}
@media (min-width: 1400px) {
  .content-wrap { margin-top: 50vh; } /* even lower on very wide screens */
}
  .info-card {
    width: 100%;
    max-width: 1140px;
    background: var(--card-bg);
    backdrop-filter: blur(8px);
    border: 1px solid var(--stroke);
    border-radius: 20px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, .18);
    padding: clamp(18px, 3.6vw, 32px);
    animation: transitionIn-Y-bottom .6s;
  }

  .mv-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: clamp(12px, 2vw, 16px);
    margin-bottom: clamp(16px, 2.4vw, 22px);
  }
  @media (min-width: 860px){
    .mv-grid { grid-template-columns: 1fr 1fr; }
  }

  .section-title {
    font-size: clamp(20px, 2.2vw, 24px);
    font-weight: 800;
    margin: 4px 0 10px;
    color: var(--accent);
    letter-spacing: .2px;
  }

  .mv-box {
    background: #fff;
    border: 1px solid var(--stroke);
    border-radius: 14px;
    padding: clamp(12px, 2.4vw, 16px);
    box-shadow: 0 6px 16px rgba(17, 75, 95, .08);
  }

  .mv-text {
    color: #334155;
    line-height: 1.65;
    font-size: clamp(14px, 1.6vw, 15.5px);
    margin: 0;
  }

  .services { margin-top: clamp(14px, 2.2vw, 18px); }
  .services-list {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
    list-style: none;
    padding: 0; margin: 0;
  }

  @media (min-width: 640px){ 
  .services-list { 
  grid-template-columns: 1fr 1fr; 
  } 
}
  @media (min-width: 1060px){
    .services-list { 
      grid-template-columns: 1fr 1fr 1fr; 
    } 
  }

  .service-pill {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: #fff;
    border: 1px solid var(--stroke);
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 14.5px;
    color: var(--ink);
    box-shadow: 0 4px 12px rgba(17, 75, 95, .06);
    transition: transform .18s ease, box-shadow .18s ease;
  }
  .service-pill:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(17, 75, 95, .12);
  }
  .svc-icon {
    flex: 0 0 auto;
    width: 18px; height: 18px;
    margin-top: 1.5px;
    opacity: .9;
  }

  /* ===== Footer ===== */
footer {
  background: rgba(57, 160, 62, 0.42);
  backdrop-filter: blur(4px);
  color: #f1f5f9;
  padding: 40px 20px 20px;
  margin-top: 150px;
  font-family: 'Segoe UI', sans-serif;
}

.footer-container {
  max-width: 1140px;
  margin: auto;
  display: grid;
  grid-template-columns: 1fr;
  gap: 30px;
}

@media (min-width: 720px) {
  .footer-container {
    grid-template-columns: 2fr 1fr 1fr;
  }
}

.footer-col h3 {
  font-size: 18px;
  margin-bottom: 15px;
  font-weight: 600;
  color: #fff;
}

.footer-col p,
.footer-col a {
  font-size: 14px;
  color: white;
  text-decoration: none;
  line-height: 1.6;
}

.footer-col a:hover {
  color: #ffffff;
  text-decoration: underline;
}

.footer-social {
  display: flex;
  gap: 12px;
  margin-top: 10px;
}

.footer-social a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #fff;
  color: rgba(17, 95, 21, 0.9);
  width: 28px;
  height: 28px;
  border-radius: 50%;
  font-size: 14px;
  transition: background .3s, color .3s;
}

.footer-social a:hover {
  background: #0f172a;
  color: #fff;
}

.footer-bottom {
  text-align: center;
  font-size: 13px;
  margin-top: 30px;
  border-top: 1px solid rgba(255,255,255,.2);
  padding-top: 15px;
  color: #cbd5e1;
}
/* Hero glass box for readability */
.hero-box{
  max-width: 960px;
  margin: 40vh auto 0;        /* push down from the top */
  padding: 22px 28px;
  text-align: center;
}
.hero-box .heading-text{
  color: #fff;
  margin: 0 0 10px;
  font-weight: 800;

}
.hero-box .sub-text2{
  color: #f8fafc;
  line-height: 1.7;
  margin: 0;
  text-shadow: 0 2px 8px rgba(0,0,0,.7);
  font-size: clamp(14px, 1.7vw, 16px);
}

@media (max-width: 640px){
  .hero-box{ margin-top: 12vh; padding: 18px 16px; }
}

/* ===== Compact Header Bar ===== */
.header-bar {
  width: 100%;
  background: rgba(57, 160, 62, 0.42); 
  backdrop-filter: blur(4px);
  padding: 6px 16px;        
  position: fixed;
  top: 0;
  left: 0;
  z-index: 1000;
}

.header-bar table {
  width: 100%;
  color: #fff;
}

.header-bar .logo-img {
  max-height: 55px;        /* smaller logo */
  margin-left: 8px;
  border-radius: 50%;
}

.header-bar .edoc-logo {
  font-size: 16px;         /* smaller text */
  font-weight: 600;
  color: #fff;
  margin-left: 6px;
}

.header-bar .nav-item {
  color: #fff;
  font-weight: 500;
  font-size: 14px;         /* smaller font */
  margin: 0;
  transition: color .2s;
}

.header-bar .nav-item:hover {
  color: #dbeafe;
}


</style>

</head>
<body>
  <div class="bg-gradient"></div>
       <div class="header-bar">
  <table border="0">
    <tr>
      <td width="70%">
        <img src="<?php echo $logoURL; ?>" alt="Logo" class="logo-img">
        <span class="edoc-logo">| Gentri Medical Center and Hospital</span>
      </td>
      <td width="10%" style="text-align:right;">
        <a href="login.php" class="non-style-link"><p class="nav-item" style="padding-right: 10px;">LOGIN</p></a>
      </td>
    </tr>
  </table>
</div>


                <tr>
                <td colspan="2">
                    <div class="hero-box">
                    <p class="heading-text">Committed to Your Health</p>
                    <p class="sub-text2">
                        Gentri Medical Center and Hospital is dedicated to providing compassionate, high-quality healthcare for every patient.<br>
                        With expert doctors, modern facilities, and a patient-centered approach, we are here to care for you and your family.<br>
                        Your health is our priority—because at Gentri Medical, you are in safe and trusted hands.
                    </p>
                    </div>
                </td>
                </tr>

            </table>
        </center>
    </div>

   <!-- ====== Refined Mission, Vision & Services Section ====== -->
<div class="content-wrap">
  <div class="info-card">
    <div class="mv-grid">
      <div class="mv-box">
        <div class="section-title">Our Mission</div>
        <p class="mv-text">
          <?php echo htmlspecialchars($mission, ENT_QUOTES, 'UTF-8'); ?>
        </p>
      </div>
      <div class="mv-box">
        <div class="section-title">Our Vision</div>
        <p class="mv-text">
          <?php echo htmlspecialchars($vision, ENT_QUOTES, 'UTF-8'); ?>
        </p>
      </div>
    </div>

<div class="services" id="services">

      <div class="section-title">Our Services</div>
      <ul class="services-list">
        <?php foreach ($services as $svc): ?>
          <li class="service-pill">
            <svg class="svc-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10Z" stroke="currentColor" stroke-width="1.6"/>
              <path d="m8.5 12.5 2.6 2.6 4.4-5.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?php echo htmlspecialchars($svc, ENT_QUOTES, 'UTF-8'); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<!-- ===== Footer Section ===== -->
<footer>
  <div class="footer-container">
    <!-- Column 1: About / Contact -->
    <div class="footer-col">
      <h3>Gentri Medical Center and Hospital</h3>
      <p>Gentri Medical Center And Hospital Inc. Santusan St, General Trias</p>
      <p>Phone: (046) 429 8888</p>
      <p>Email: info@gentrihospital.ph</p>
    </div>

    <!-- Column 2: Quick Links -->
    <div class="footer-col">
      <h3>Quick Links</h3>
      <p><a href="login.php">Login</a></p>
    </div>

    <!-- Column 3: Social Media -->
    <div class="footer-col">
      <h3>Follow Us</h3>
      <div class="footer-social">
        <a href="https://www.facebook.com/profile.php?id=100067220635425&sk=about"><i class="fab fa-facebook-f"></i></a>
      </div>
    </div>
  </div>

  <div class="footer-bottom">
    &copy; <?php echo date("Y"); ?> Gentri Medical Center and Hospital. All Rights Reserved.
  </div>
</footer>


</body>
</html>
