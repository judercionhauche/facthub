<?php
// Registration type chooser
?>

<div style="max-width: 900px; margin: 60px auto; padding: 0 20px;">
    <h1 style="text-align: center; margin-bottom: 50px; font-size: 32px; color: #1a3d2a;">Join FACT Alliance Hub</h1>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 40px;">
        <!-- Researcher Card -->
        <a href="index.php?page=researchers&mode=add" style="text-decoration: none; color: inherit;">
            <div style="
                border: 2px solid #dde6dd;
                border-radius: 12px;
                padding: 40px 30px;
                text-align: center;
                background: #ffffff;
                transition: all 0.3s ease;
                cursor: pointer;
            " onmouseover="this.style.borderColor='#1a6b5a'; this.style.boxShadow='0 4px 12px rgba(26,107,90,0.15)'" onmouseout="this.style.borderColor='#dde6dd'; this.style.boxShadow='none'">
                <div style="font-size: 48px; margin-bottom: 20px;">👨‍🔬</div>
                <h2 style="font-size: 24px; margin: 0 0 15px 0; color: #1a3d2a;">Register as Researcher</h2>
                <p style="color: #60706a; font-size: 14px; line-height: 1.6; margin: 0;">
                    Connect with funding opportunities that match your research interests and expertise.
                </p>
            </div>
        </a>

        <!-- Funder Card -->
        <a href="index.php?page=funders&mode=add" style="text-decoration: none; color: inherit;">
            <div style="
                border: 2px solid #dde6dd;
                border-radius: 12px;
                padding: 40px 30px;
                text-align: center;
                background: #ffffff;
                transition: all 0.3s ease;
                cursor: pointer;
            " onmouseover="this.style.borderColor='#1a6b5a'; this.style.boxShadow='0 4px 12px rgba(26,107,90,0.15)'" onmouseout="this.style.borderColor='#dde6dd'; this.style.boxShadow='none'">
                <div style="font-size: 48px; margin-bottom: 20px;">💰</div>
                <h2 style="font-size: 24px; margin: 0 0 15px 0; color: #1a3d2a;">Register as Funder</h2>
                <p style="color: #60706a; font-size: 14px; line-height: 1.6; margin: 0;">
                    Post funding opportunities and discover researchers working on your priority topics.
                </p>
            </div>
        </a>
    </div>

    <div style="text-align: center; color: #9aaba4; font-size: 14px;">
        Already have an account? <a href="index.php?page=login" style="color: #1a6b5a; text-decoration: none; font-weight: 600;">Sign in →</a>
    </div>
</div>
