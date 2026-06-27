<?php
/**
 * header-auth.php - Shared header for user authentication pages
 * Redirects logo to admin login as per request
 */
?>
<header style="position: absolute; top: 0; left: 0; width: 100%; padding: 2.5rem 4rem; z-index: 1000; box-sizing: border-box;">
    <div style="max-width: 1400px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between;">
        <a href="../admin/login.php" style="text-decoration: none; display: flex; align-items: center; gap: 1rem; transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
            <div style="background: linear-gradient(135deg, #6366f1 0%, #22d3ee 100%); width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 1.25rem; box-shadow: 0 12px 24px -6px rgba(99, 102, 241, 0.5); border: 1px solid rgba(255,255,255,0.1);">
                AF
            </div>
            <span style="color: white; font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; text-shadow: 0 2px 10px rgba(0,0,0,0.2);">
                AMYFI
            </span>
        </a>
    </div>
</header>

<style>
    @media (max-width: 850px) {
        header {
            padding: 2rem 1.5rem !important;
        }
        header a span {
            display: block !important; /* Ensure it stays visible */
        }
    }
</style>
