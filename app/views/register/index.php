<?php
// Registration type chooser
?>

<div style="max-width: 900px; margin: 60px auto; padding: 0 20px;">
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
                <div style="width: 60px; height: 60px; background: #f0f4f3; border-radius: 12px; margin: 0 auto 24px; display: flex; align-items: center; justify-content: center; font-size: 28px; color: #1a6b5a;">📊</div>
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
                <div style="width: 60px; height: 60px; background: #f0f4f3; border-radius: 12px; margin: 0 auto 24px; display: flex; align-items: center; justify-content: center; font-size: 28px; color: #1a6b5a;">💼</div>
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
