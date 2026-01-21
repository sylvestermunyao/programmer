<?php
require_once 'include/db.php';
require_once 'include/header.php';

// Get available vehicles from database
$vehicles = [];
$stats = [];
try {
    $stmt = $db->prepare("
        SELECT v.*, vc.name as category_name, vc.description as category_description 
        FROM available_vehicles_view v 
        ORDER BY v.rate_per_day ASC 
        LIMIT 6
    ");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();

    // Get company info from settings
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name'");
    $stmt->execute();
    $company_name = $stmt->fetchColumn();

    // Get stats
    $stmt = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM vehicles WHERE status = 'available') as available_vehicles,
            (SELECT COUNT(*) FROM customers WHERE customer_status = 'active') as happy_customers
    ");
    $stats = $stmt->fetch();

} catch (PDOException $e) {
    error_log("Error fetching data: " . $e->getMessage());
}

// Define car images for hero section
$hero_cars = [
    'car1.jpg',
    'car2.jpg', 
    'car3.jpg'
];
?>

<section class="hero">
    <div class="hero-background">
        <div class="floating-shapes">
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>
        </div>
    </div>
    
    <!-- Car Images Container -->
    <div class="cars-container">
        <?php foreach ($hero_cars as $index => $car): ?>
        <div class="car-slide <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
            <img src="images/<?= $car ?>" alt="Premium Rental Car <?= $index + 1 ?>" class="car-image">
            <div class="car-overlay"></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="container">
        <div class="hero-content">
            <div class="hero-text">
                <div class="badge">Premium Service</div>
                <h1 class="hero-title">
                    <span class="title-line"> JKUAT-OCHMS</span>
                    <span class="title-line accent">Experience</span>
                </h1>
                <p class="hero-description">Discover our exclusive fleet of luxury and economy vehicles. Perfect for every occasion with unmatched service and reliability.</p>
                <div class="hero-buttons">
                    <a href="#contact" class="btn btn-primary">
                        <span>Contact us</span>
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16.59 8.59L12 13.17 7.41 8.59 6 10l6 6 6-6z"/>
                        </svg>
                    </a>
                    <a href="client/login.php" class="btn btn-secondary">
                        <span>Hire Now</span>
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/>
                        </svg>
                    </a>
                </div>
             </div>
            </div>
    </div>

    <!-- Car Navigation Dots -->
    <div class="car-nav">
        <?php foreach ($hero_cars as $index => $car): ?>
        <button class="nav-dot <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>"></button>
        <?php endforeach; ?>
    </div>
</section></br></br></br></br></br></br></br></br>

<section id="features" class="features">
    <div class="container">
        <div class="section-header">
            <div class="badge">Why Choose Us</div>
            <h2>JKUAT-OCHMS Benefits</h2>
            <p>Experience the difference with our exceptional service and premium fleet</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <div class="icon-wrapper">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
                        </svg>
                    </div>
                </div>
                <h3>Fully Insured</h3>
                <p>Comprehensive insurance coverage for complete peace of mind during your rental period</p>
                <div class="feature-decoration"></div>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <div class="icon-wrapper">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>
                        </svg>
                    </div>
                </div>
                <h3>24/7 Support</h3>
                <p>Round-the-clock customer service and roadside assistance whenever you need it</p>
                <div class="feature-decoration"></div>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <div class="icon-wrapper">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9c.83 0 1.5-.67 1.5-1.5 0-.39-.15-.74-.39-1.01-.23-.26-.38-.61-.38-.99 0-.83.67-1.5 1.5-1.5H16c2.76 0 5-2.24 5-5 0-4.42-4.03-8-9-8zm-5.5 9c-.83 0-1.5-.67-1.5-1.5S5.67 9 6.5 9 8 9.67 8 10.5 7.33 12 6.5 12zm3-4C8.67 8 8 7.33 8 6.5S8.67 5 9.5 5s1.5.67 1.5 1.5S10.33 8 9.5 8zm5 0c-.83 0-1.5-.67-1.5-1.5S13.67 5 14.5 5s1.5.67 1.5 1.5S15.33 8 14.5 8zm3 4c-.83 0-1.5-.67-1.5-1.5S16.67 9 17.5 9s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/>
                        </svg>
                    </div>
                </div>
                <h3>Well Maintained</h3>
                <p>Regularly serviced vehicles ensuring safety, reliability and optimal performance</p>
                <div class="feature-decoration"></div>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <div class="icon-wrapper">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.78-1.18 2.73-3.12 3.16z"/>
                        </svg>
                    </div>
                </div>
                <h3>Best Prices</h3>
                <p>Competitive rates with transparent pricing and no hidden charges guaranteed</p>
                <div class="feature-decoration"></div>
            </div>
        </div>
    </div>
</section>
<section id="about" class="about">
    <div class="container">
        <div class="about-content">
            <div class="about-text">
                <div class="section-header">
                    <div class="badge">About Us</div>
                    <h2>JKUAT Rentals Excellence</h2>
                </div>
                <p class="about-description">With years of experience in the car rental industry, we pride ourselves on delivering exceptional service and maintaining the highest standards of vehicle quality and customer care. Our commitment to excellence ensures every journey is memorable.</p>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number" data-count="<?= $stats['happy_customers'] ?? '500' ?>">0</div>
                            <div class="stat-label">Happy Customers</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.22.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number" data-count="<?= $stats['available_vehicles'] ?? '50' ?>">0</div>
                            <div class="stat-label">Vehicles Available</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">24/7</div>
                            <div class="stat-label">Support</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="contact" class="contact">
    <div class="container">
        <div class="section-header">
            <div class="badge">Get In Touch</div>
            <h2>Contact Us</h2>
            <p>We're here to help you with your car rental needs</p>
        </div>
        <div class="contact-content">
            <div class="contact-info">
                <div class="contact-card">
                    <div class="contact-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                        </svg>
                    </div>
                    <div class="contact-details">
                        <h3>Visit Our Office</h3>
                        <p>JKUAT<br>Nairobi, Kenya</p>
                    </div>
                </div>
                <div class="contact-card">
                    <div class="contact-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                        </svg>
                    </div>
                    <div class="contact-details">
                        <h3>Call Us</h3>
                        <p>+254707874296<br>+254714746703</p>
                    </div>
                </div>
                <div class="contact-card">
                    <div class="contact-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                        </svg>
                    </div>
                    <div class="contact-details">
                        <h3>Email Us</h3>
                        <p>info@JkuatOchms.com<br>suppor@JkuatOchms.com</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
    :root {
        --primary-color: #2563eb;
        --primary-dark: #1d4ed8;
        --primary-light: #3b82f6;
        --surface-color: #ffffff;
        --background-color: #f8fafc;
        --text-primary: #1e293b;
        --text-secondary: #64748b;
        --border-color: #e2e8f0;
        --border-radius: 8px;
        --border-radius-lg: 12px;
        --border-radius-xl: 16px;
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        line-height: 1.6;
        color: var(--text-primary);
        background-color: var(--background-color);
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
    }

    .badge {
        display: inline-block;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 1rem;
    }

    .section-header {
        text-align: center;
        margin-bottom: 4rem;
    }

    .section-header h2 {
        font-size: 3rem;
        font-weight: 700;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--text-primary), var(--primary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .section-header p {
        font-size: 1.125rem;
        color: var(--text-secondary);
        max-width: 600px;
        margin: 0 auto;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 1rem 2rem;
        border: none;
        border-radius: var(--border-radius);
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        transition: var(--transition);
        font-size: 0.875rem;
        line-height: 1;
        position: relative;
        overflow: hidden;
    }

    .btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        transition: left 0.5s;
    }

    .btn:hover::before {
        left: 100%;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        color: white;
        box-shadow: var(--shadow-lg);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-xl);
    }

    .btn-secondary {
        background: transparent;
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
    }

    .btn-secondary:hover {
        background: var(--primary-color);
        color: white;
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }

    .btn-full {
        width: 100%;
    }

    .btn svg {
        width: 16px;
        height: 16px;
    }

    /* Hero Section */
    .hero {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
        color: white;
        position: relative;
        overflow: hidden;
        min-height: 100vh;
        display: flex;
        align-items: center;
    }

    .hero-background {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 1;
    }

    .floating-shapes {
        position: absolute;
        width: 100%;
        height: 100%;
    }

    .shape {
        position: absolute;
        border-radius: 50%;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(37, 99, 235, 0.05));
        animation: float 6s ease-in-out infinite;
    }

    .shape-1 {
        width: 200px;
        height: 200px;
        top: 10%;
        left: 10%;
        animation-delay: 0s;
    }

    .shape-2 {
        width: 150px;
        height: 150px;
        top: 60%;
        right: 10%;
        animation-delay: 2s;
    }

    .shape-3 {
        width: 100px;
        height: 100px;
        bottom: 20%;
        left: 20%;
        animation-delay: 4s;
    }

    /* Cars Container */
    .cars-container {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 2;
    }

    .car-slide {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        transition: opacity 1s ease-in-out;
    }

    .car-slide.active {
        opacity: 1;
    }

    .car-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transform: scale(1.2);
        animation: carDriveIn 8s ease-in-out forwards;
        filter: brightness(0.7);
    }

    .car-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(
            90deg,
            rgba(15, 23, 42, 0.8) 0%,
            rgba(15, 23, 42, 0.6) 50%,
            rgba(15, 23, 42, 0.8) 100%
        );
    }

    /* Car Navigation */
    .car-nav {
        position: absolute;
        bottom: 2rem;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 0.5rem;
        z-index: 10;
    }

    .nav-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: none;
        background: rgba(255, 255, 255, 0.3);
        cursor: pointer;
        transition: var(--transition);
    }

    .nav-dot.active {
        background: white;
        transform: scale(1.2);
    }

    .nav-dot:hover {
        background: rgba(255, 255, 255, 0.6);
    }

    .hero-content {
        position: relative;
        z-index: 3;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4rem;
        align-items: center;
        padding: 4rem 0;
    }

    .hero-title {
        font-size: 3.5rem;
        font-weight: 800;
        line-height: 1.1;
        margin-bottom: 1.5rem;
    }

    .title-line {
        display: block;
    }

    .title-line.accent {
        background: linear-gradient(135deg, #60a5fa, var(--primary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .hero-description {
        font-size: 1.25rem;
        color: #cbd5e1;
        margin-bottom: 2.5rem;
        line-height: 1.6;
    }

    .hero-buttons {
        display: flex;
        gap: 1rem;
        margin-bottom: 3rem;
    }

    .hero-stats {
        display: flex;
        gap: 3rem;
    }

    .stat-item {
        text-align: center;
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        color: var(--primary-light);
        margin-bottom: 0.5rem;
    }

    .stat-label {
        font-size: 0.875rem;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .hero-visual {
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .car-showcase {
        position: relative;
    }

    .floating-elements {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
    }

    .element {
        position: absolute;
        font-size: 1.5rem;
        animation: float 3s ease-in-out infinite;
    }

    .element-1 {
        top: 10%;
        right: 10%;
        animation-delay: 0s;
    }

    .element-2 {
        bottom: 20%;
        left: 10%;
        animation-delay: 1s;
    }

    .element-3 {
        top: 40%;
        right: 20%;
        animation-delay: 2s;
    }

    /* Features Section */
    .features {
        padding: 8rem 0;
        background: var(--surface-color);
    }

    .features-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 2rem;
    }

    .feature-card {
        background: var(--surface-color);
        padding: 2.5rem;
        border-radius: var(--border-radius-xl);
        text-align: center;
        box-shadow: var(--shadow-lg);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        border: 1px solid var(--border-color);
    }

    .feature-card:hover {
        transform: translateY(-8px);
        box-shadow: var(--shadow-xl);
    }

    .feature-icon {
        margin-bottom: 1.5rem;
    }

    .icon-wrapper {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, var(--primary-light), var(--primary-color));
        border-radius: 50%;
        color: white;
    }

    .icon-wrapper svg {
        width: 32px;
        height: 32px;
    }

    .feature-card h3 {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 1rem;
        color: var(--text-primary);
    }

    .feature-card p {
        color: var(--text-secondary);
        line-height: 1.6;
    }

    .feature-decoration {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--primary-light), var(--primary-color));
        transform: scaleX(0);
        transition: transform 0.3s ease;
    }

    .feature-card:hover .feature-decoration {
        transform: scaleX(1);
    }

    /* Vehicles Section */
    .vehicles {
        padding: 8rem 0;
        background: var(--background-color);
    }

    .vehicles-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 2rem;
    }

    .vehicle-card {
        opacity: 0;
        transform: translateY(30px);
        transition: opacity 0.6s ease, transform 0.6s ease;
    }

    .vehicle-card.visible {
        opacity: 1;
        transform: translateY(0);
    }

    .card-inner {
        background: var(--surface-color);
        border-radius: var(--border-radius-xl);
        overflow: hidden;
        box-shadow: var(--shadow-lg);
        transition: var(--transition);
        border: 1px solid var(--border-color);
    }

    .vehicle-card:hover .card-inner {
        transform: translateY(-8px);
        box-shadow: var(--shadow-xl);
    }

    .vehicle-image {
        background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
        height: 200px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        position: relative;
    }

    .image-placeholder svg {
        width: 80px;
        height: 80px;
        filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
    }

    .vehicle-badge {
        position: absolute;
        top: 1rem;
        right: 1rem;
        background: rgba(255,255,255,0.2);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 600;
        backdrop-filter: blur(10px);
    }

    .vehicle-info {
        padding: 2rem;
    }

    .vehicle-header {
        display: flex;
        justify-content: between;
        align-items: start;
        margin-bottom: 0.5rem;
    }

    .vehicle-header h3 {
        font-size: 1.25rem;
        font-weight: 700;
        flex: 1;
    }

    .vehicle-year {
        background: var(--primary-light);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .vehicle-category {
        color: var(--text-secondary);
        font-size: 0.875rem;
        margin-bottom: 1.5rem;
    }

    .vehicle-specs {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    .spec {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    .spec svg {
        width: 16px;
        height: 16px;
        color: var(--primary-color);
    }

    .vehicle-pricing {
        display: flex;
        align-items: baseline;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        padding: 1rem;
        background: var(--background-color);
        border-radius: var(--border-radius);
    }

    .price-main {
        display: flex;
        align-items: baseline;
        gap: 0.25rem;
    }

    .currency {
        font-size: 0.875rem;
        color: var(--text-secondary);
        font-weight: 600;
    }

    .amount {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--primary-color);
    }

    .price-period {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    .no-vehicles {
        grid-column: 1 / -1;
        text-align: center;
        padding: 4rem;
        color: var(--text-secondary);
    }

    .no-vehicles svg {
        width: 64px;
        height: 64px;
        color: var(--border-color);
        margin-bottom: 1rem;
    }

    /* About Section */
    .about {
        padding: 8rem 0;
        background: linear-gradient(135deg, var(--surface-color) 0%, var(--background-color) 100%);
    }

    .about-text {
        max-width: 800px;
        margin: 0 auto;
        text-align: center;
    }

    .about-description {
        font-size: 1.125rem;
        color: var(--text-secondary);
        margin-bottom: 3rem;
        line-height: 1.7;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 2rem;
        margin-top: 3rem;
    }

    .stat-card {
        background: var(--surface-color);
        padding: 2rem;
        border-radius: var(--border-radius-lg);
        text-align: center;
        box-shadow: var(--shadow-md);
        transition: var(--transition);
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, var(--primary-light), var(--primary-color));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        color: white;
    }

    .stat-icon svg {
        width: 24px;
        height: 24px;
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
    }

    .stat-label {
        color: var(--text-secondary);
        font-weight: 600;
    }

    /* Contact Section */
    .contact {
        padding: 8rem 0;
        background: var(--surface-color);
    }

    .contact-content {
        max-width: 800px;
        margin: 0 auto;
    }

    .contact-info {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 2rem;
    }

    .contact-card {
        background: var(--surface-color);
        padding: 2rem;
        border-radius: var(--border-radius-lg);
        text-align: center;
        box-shadow: var(--shadow-md);
        transition: var(--transition);
        border: 1px solid var(--border-color);
    }

    .contact-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .contact-icon {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, var(--primary-light), var(--primary-color));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        color: white;
    }

    .contact-icon svg {
        width: 24px;
        height: 24px;
    }

    .contact-details h3 {
        margin-bottom: 0.5rem;
        font-size: 1.25rem;
        font-weight: 700;
    }

    .contact-details p {
        color: var(--text-secondary);
        line-height: 1.6;
    }

    /* Animations */
    @keyframes float {
        0%, 100% {
            transform: translateY(0) rotate(0deg);
        }
        50% {
            transform: translateY(-20px) rotate(5deg);
        }
    }

    @keyframes carDriveIn {
        0% {
            transform: scale(1.5) translateX(-100px);
            filter: brightness(0.5) blur(5px);
        }
        20% {
            transform: scale(1.3) translateX(0);
            filter: brightness(0.6) blur(3px);
        }
        40% {
            transform: scale(1.2) translateX(0);
            filter: brightness(0.7) blur(1px);
        }
        60% {
            transform: scale(1.15) translateX(0);
            filter: brightness(0.75) blur(0px);
        }
        80% {
            transform: scale(1.1) translateX(0);
            filter: brightness(0.8) blur(0px);
        }
        100% {
            transform: scale(1.2) translateX(0);
            filter: brightness(0.7) blur(0px);
        }
    }

    @keyframes carZoom {
        0% {
            transform: scale(1.2);
        }
        100% {
            transform: scale(1.1);
        }
    }

    /* Responsive Design */
    @media (max-width: 1024px) {
        .hero-content {
            grid-template-columns: 1fr;
            gap: 3rem;
            text-align: center;
        }

        .hero-title {
            font-size: 3rem;
        }

        .section-header h2 {
            font-size: 2.5rem;
        }

        .car-image {
            animation: carDriveIn 6s ease-in-out forwards;
        }
    }

    @media (max-width: 768px) {
        .hero-title {
            font-size: 2.5rem;
        }

        .hero-buttons {
            flex-direction: column;
        }

        .hero-stats {
            flex-direction: column;
            gap: 1.5rem;
        }

        .section-header h2 {
            font-size: 2rem;
        }

        .features-grid,
        .vehicles-grid {
            grid-template-columns: 1fr;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .contact-info {
            grid-template-columns: 1fr;
        }

        .car-nav {
            bottom: 1rem;
        }

        .car-image {
            animation: carDriveIn 5s ease-in-out forwards;
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 0 1rem;
        }

        .hero-title {
            font-size: 2rem;
        }

        .hero-description {
            font-size: 1rem;
        }

        .section-header h2 {
            font-size: 1.75rem;
        }

        .feature-card,
        .vehicle-card,
        .stat-card,
        .contact-card {
            padding: 1.5rem;
        }

        .car-image {
            animation: carDriveIn 4s ease-in-out forwards;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Car Slider Functionality
        let currentCarIndex = 0;
        const carSlides = document.querySelectorAll('.car-slide');
        const navDots = document.querySelectorAll('.nav-dot');
        const totalCars = carSlides.length;
        let carInterval;

        function showCar(index) {
            // Remove active class from all slides and dots
            carSlides.forEach(slide => slide.classList.remove('active'));
            navDots.forEach(dot => dot.classList.remove('active'));
            
            // Add active class to current slide and dot
            carSlides[index].classList.add('active');
            navDots[index].classList.add('active');
            
            // Reset animation for the active slide
            const activeImage = carSlides[index].querySelector('.car-image');
            activeImage.style.animation = 'none';
            setTimeout(() => {
                activeImage.style.animation = 'carDriveIn 8s ease-in-out forwards';
            }, 10);
        }

        function nextCar() {
            currentCarIndex = (currentCarIndex + 1) % totalCars;
            showCar(currentCarIndex);
        }

        function startCarSlider() {
            carInterval = setInterval(nextCar, 5000); // Change car every 5 seconds
        }

        function stopCarSlider() {
            clearInterval(carInterval);
        }

        // Initialize car slider
        showCar(currentCarIndex);
        startCarSlider();

        // Add click events to navigation dots
        navDots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                stopCarSlider();
                currentCarIndex = index;
                showCar(currentCarIndex);
                startCarSlider();
            });
        });

        // Pause slider on hover
        const carsContainer = document.querySelector('.cars-container');
        carsContainer.addEventListener('mouseenter', stopCarSlider);
        carsContainer.addEventListener('mouseleave', startCarSlider);

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Animate numbers counting up
        function animateNumbers() {
            const numberElements = document.querySelectorAll('[data-count]');
            
            numberElements.forEach(element => {
                const target = parseInt(element.getAttribute('data-count'));
                const duration = 2000; // 2 seconds
                const step = target / (duration / 16); // 60fps
                let current = 0;
                
                const timer = setInterval(() => {
                    current += step;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    element.textContent = Math.floor(current).toLocaleString();
                }, 16);
            });
        }

        // Intersection Observer for scroll animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    if (entry.target.classList.contains('vehicle-card')) {
                        const delay = entry.target.getAttribute('data-delay') || 0;
                        setTimeout(() => {
                            entry.target.classList.add('visible');
                        }, delay);
                    } else {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                    
                    // Animate numbers when stats section comes into view
                    if (entry.target.id === 'about') {
                        animateNumbers();
                    }
                }
            });
        }, observerOptions);

        // Observe elements for animation
        document.querySelectorAll('.feature-card, .vehicle-card, .stat-card, .contact-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });

        // Observe about section for number animation
        const aboutSection = document.getElementById('about');
        if (aboutSection) {
            observer.observe(aboutSection);
        }

        // Add hover effects to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            button.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });

        // Parallax effect for hero background
        window.addEventListener('scroll', function() {
            const scrolled = window.pageYOffset;
            const hero = document.querySelector('.hero');
            if (hero) {
                const rate = scrolled * 0.5;
                hero.style.transform = `translateY(${rate}px)`;
            }
        });

        // Initialize all vehicle cards as hidden
        document.querySelectorAll('.vehicle-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
        });
    });

    // Add loading animation
    window.addEventListener('load', function() {
        document.body.style.opacity = '0';
        document.body.style.transition = 'opacity 0.3s ease';
        
        setTimeout(() => {
            document.body.style.opacity = '1';
        }, 100);
    });
</script>

<?php require_once 'include/footer.php'; ?>