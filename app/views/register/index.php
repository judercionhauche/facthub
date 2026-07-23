<?php
// Registration type chooser
?>

<div class="register-wheat-bg" style="max-width: 900px; margin: 60px auto; padding: 0 20px; position: relative; background-image: url('wheat.avif'); background-size: cover; background-position: center; background-attachment: fixed;">
    <h1 style="text-align: center; margin-bottom: 50px; font-size: 32px; color: #1a3d2a;">Join FACT Alliance Hub</h1>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 40px;">
        <!-- Researcher Card -->
        <a href="index.php?page=researchers&mode=add" style="text-decoration: none; color: inherit;">
            <div style="
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 50px 40px;
                text-align: center;
                background: #ffffff;
                transition: all 0.2s ease;
                cursor: pointer;
            " onmouseover="this.style.boxShadow='0 10px 25px rgba(0,0,0,0.08)'; this.style.borderColor='#d1d5db'; this.style.transform='translateY(-2px)'" onmouseout="this.style.boxShadow='none'; this.style.borderColor='#e5e7eb'; this.style.transform='translateY(0)'">
                <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #1a6b5a 0%, #2a9b84 100%); border-radius: 12px; margin: 0 auto 24px; display: flex; align-items: center; justify-content: center;">
                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="16" cy="10" r="3" fill="white" stroke="white" stroke-width="1.5"/>
                        <path d="M16 14V24M12 18H20M10 22H22" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <h2 style="font-size: 22px; margin: 0 0 12px 0; color: #111827; font-weight: 600;">Researcher</h2>
                <p style="color: #6b7280; font-size: 15px; line-height: 1.6; margin: 0;">
                    Connect with funding opportunities that match your research interests and expertise.
                </p>
            </div>
        </a>

        <!-- Funder Card (Disabled) -->
        <div onclick="showUnavailableModal()" style="text-decoration: none; color: inherit; cursor: not-allowed;">
            <div style="
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 50px 40px;
                text-align: center;
                background: #f9fafb;
                opacity: 0.6;
                transition: all 0.2s ease;
                cursor: not-allowed;
                position: relative;
            ">
                <div style="position: absolute; top: 12px; right: 12px; background: #f0f4f3; color: #1a6b5a; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">Coming Soon</div>
                <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #1a6b5a 0%, #2a9b84 100%); border-radius: 12px; margin: 0 auto 24px; display: flex; align-items: center; justify-content: center;">
                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M6 12V26C6 27.1046 6.89543 28 8 28H24C25.1046 28 26 27.1046 26 26V12" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M10 12H22C23.1046 12 24 11.1046 24 10V8C24 6.89543 23.1046 6 22 6H10C8.89543 6 8 6.89543 8 8V10C8 11.1046 8.89543 12 10 12Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M14 18V24" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M18 18V24" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <h2 style="font-size: 22px; margin: 0 0 12px 0; color: #111827; font-weight: 600;">Funder</h2>
                <p style="color: #6b7280; font-size: 15px; line-height: 1.6; margin: 0;">
                    Post funding opportunities and discover researchers working on your priority topics.
                </p>
            </div>
        </div>
    </div>

    <div style="text-align: center; color: #9aaba4; font-size: 14px;">
        Already have an account? <a href="index.php?page=login" style="color: #1a6b5a; text-decoration: none; font-weight: 600;">Sign in →</a>
    </div>
</div>

<script>
function showUnavailableModal() {
    const modal = document.createElement('div');
    modal.id = 'unavailable-modal';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
    `;

    modal.innerHTML = `
        <div style="
            background: white;
            border-radius: 12px;
            padding: 40px;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        ">
            <h2 style="margin: 0 0 12px 0; font-size: 22px; color: #111827;">Feature Coming Soon</h2>
            <p style="color: #6b7280; font-size: 15px; line-height: 1.6; margin: 0 0 24px 0;">
                The Funder registration is not yet available. We're working on it and will launch it soon. Check back later!
            </p>
            <button onclick="document.getElementById('unavailable-modal').remove()" style="
                background: #1a6b5a;
                color: white;
                border: none;
                padding: 10px 24px;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            " onmouseover="this.style.background='#164d44'" onmouseout="this.style.background='#1a6b5a'">
                Got it
            </button>
        </div>
    `;

    document.body.appendChild(modal);
    modal.onclick = (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    };
}
</script>

<style>
/* Register page with maximum wheat visibility */
.register-wheat-bg::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg,
        rgba(255, 255, 255, 0.80) 0%,
        rgba(238, 243, 239, 0.78) 50%,
        rgba(255, 255, 255, 0.82) 100%);
    backdrop-filter: blur(0.5px);
    pointer-events: none;
    z-index: 0;
    left: -20px;
    right: -20px;
    margin: -60px -20px 0 -20px;
}

.register-wheat-bg > * {
    position: relative;
    z-index: 1;
}

/* Subtle grain texture for premium feel */
.register-wheat-bg::after {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        radial-gradient(circle at 20% 50%, rgba(26, 107, 90, 0.04) 0%, transparent 50%),
        radial-gradient(circle at 80% 80%, rgba(200, 168, 90, 0.025) 0%, transparent 50%);
    pointer-events: none;
    z-index: 2;
    left: -20px;
    right: -20px;
    margin: -60px -20px 0 -20px;
}
</style>
