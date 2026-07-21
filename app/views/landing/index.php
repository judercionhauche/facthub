<?php
// Landing page for non-logged-in users
// Shows impact metrics and encourages joining the network

// Redirect logged-in users to main app
if (is_logged_in()) {
    $redirectPage = ($_SESSION['user_role'] ?? '') === 'funder' ? 'funding' : 'researchers';
    redirect_to($redirectPage);
}

// Fetch impact metrics from database
$metricsData = [];
try {
    $result = $conn->query("SELECT metric_category, metric_key, metric_value, metric_label, metric_unit FROM impact_metrics ORDER BY metric_category, order_in_category");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $cat = $row['metric_category'];
            if (!isset($metricsData[$cat])) $metricsData[$cat] = [];
            $metricsData[$cat][] = $row;
        }
    }
} catch (Exception $e) {
    error_log('[Landing] Metrics fetch error: ' . $e->getMessage());
    $metricsData = [];
}
?>

<style>
/* Landing Page Specific Styles */
.landing-hero {
    background: linear-gradient(135deg, var(--primary) 0%, #0d4f41 100%);
    color: #fff;
    padding: 140px 0 100px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.landing-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 600px;
    height: 600px;
    border-radius: 50%;
    background: rgba(111, 183, 74, 0.08);
    z-index: 0;
}

.landing-hero::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -5%;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    background: rgba(46, 139, 158, 0.06);
    z-index: 0;
}

.landing-hero-inner {
    position: relative;
    z-index: 1;
    max-width: 700px;
    margin: 0 auto;
}

.landing-hero h1 {
    font-size: 2.8rem;
    font-weight: 700;
    line-height: 1.15;
    margin-bottom: 20px;
    letter-spacing: -0.02em;
}

.landing-hero .tagline {
    font-size: 1.15rem;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 50px;
    line-height: 1.6;
    font-weight: 400;
}

.landing-hero .cta-buttons {
    display: flex;
    gap: 14px;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 80px;
}

.landing-hero .cta-buttons .btn {
    padding: 13px 28px;
    font-size: 15px;
    font-weight: 600;
}

.hero-metrics {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 24px;
    margin-top: 60px;
}

.hero-metric {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    padding: 20px;
    backdrop-filter: blur(10px);
}

.hero-metric .value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
}

.hero-metric .label {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 500;
}

/* Metrics Section with Tabs */
.metrics-section {
    padding: 80px 0;
    background: var(--bg);
}

.metrics-header {
    text-align: center;
    margin-bottom: 50px;
}

.metrics-header h2 {
    font-size: 2rem;
    color: var(--text);
    margin-bottom: 12px;
    font-weight: 700;
}

.metrics-header .subtitle {
    color: var(--muted);
    font-size: 1.05rem;
}

/* Tab Navigation */
.tab-nav {
    display: flex;
    justify-content: center;
    gap: 0;
    margin-bottom: 40px;
    border-bottom: 2px solid var(--line);
    max-width: 100%;
    overflow-x: auto;
    padding-bottom: 0;
}

.tab-nav button {
    background: none;
    border: none;
    padding: 14px 24px;
    font-size: 15px;
    font-weight: 600;
    color: var(--muted);
    cursor: pointer;
    position: relative;
    border-bottom: 3px solid transparent;
    transition: color 0.3s ease, border-color 0.3s ease;
    white-space: nowrap;
}

.tab-nav button:hover {
    color: var(--primary);
}

.tab-nav button.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}

/* Tab Content */
.tab-content {
    display: none;
    animation: fadeIn 0.4s ease;
}

.tab-content.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
}

.metric-card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 24px rgba(22, 37, 30, 0.08);
}

.metric-card .value {
    font-size: 2.2rem;
    font-weight: 700;
    color: var(--primary);
    line-height: 1;
    margin-bottom: 8px;
}

.metric-card .unit {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--primary);
    display: inline;
}

.metric-card .label {
    font-size: 13px;
    color: var(--muted);
    margin-top: 8px;
    font-weight: 500;
    line-height: 1.4;
}

/* Why Join Section */
.why-join {
    background: var(--panel);
    padding: 80px 0;
    border-top: 1px solid var(--line);
    border-bottom: 1px solid var(--line);
}

.why-join h2 {
    text-align: center;
    font-size: 2rem;
    margin-bottom: 50px;
    color: var(--text);
    font-weight: 700;
}

.value-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 24px;
    max-width: 1100px;
    margin: 0 auto;
}

.value-card {
    padding: 28px;
    background: var(--bg);
    border-radius: 12px;
    border: 1px solid var(--line);
}

.value-card .icon {
    font-size: 2.5rem;
    margin-bottom: 16px;
    display: block;
}

.value-card h3 {
    font-size: 1.2rem;
    margin-bottom: 10px;
    font-weight: 600;
    color: var(--text);
}

.value-card p {
    font-size: 14px;
    color: var(--muted);
    line-height: 1.6;
}

/* Final CTA Section */
.final-cta {
    background: linear-gradient(135deg, var(--primary) 0%, #0d4f41 100%);
    color: #fff;
    padding: 100px 0;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.final-cta::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 30% 50%, rgba(111, 183, 74, 0.1), transparent 50%);
    z-index: 0;
}

.final-cta-inner {
    position: relative;
    z-index: 1;
    max-width: 600px;
    margin: 0 auto;
}

.final-cta h2 {
    font-size: 2.4rem;
    font-weight: 700;
    margin-bottom: 20px;
    letter-spacing: -0.01em;
}

.final-cta p {
    font-size: 1.05rem;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 40px;
    line-height: 1.6;
}

.final-cta .cta-buttons {
    display: flex;
    gap: 14px;
    justify-content: center;
    flex-wrap: wrap;
}

.final-cta .cta-buttons .btn {
    padding: 13px 28px;
    font-size: 15px;
    font-weight: 600;
}

/* Responsive */
@media (max-width: 980px) {
    .landing-hero h1 {
        font-size: 2.2rem;
    }

    .landing-hero .tagline {
        font-size: 1rem;
    }

    .hero-metrics {
        grid-template-columns: repeat(2, 1fr);
    }

    .metrics-header h2 {
        font-size: 1.6rem;
    }

    .why-join h2 {
        font-size: 1.6rem;
    }

    .final-cta h2 {
        font-size: 1.8rem;
    }
}

@media (max-width: 640px) {
    .landing-hero {
        padding: 100px 0 60px;
    }

    .landing-hero h1 {
        font-size: 1.8rem;
    }

    .landing-hero .tagline {
        font-size: 0.95rem;
        margin-bottom: 30px;
    }

    .hero-metrics {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .metrics-section {
        padding: 50px 0;
    }

    .tab-nav {
        gap: 0;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .tab-nav button {
        padding: 12px 16px;
        font-size: 13px;
    }

    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .metric-card {
        padding: 16px;
    }

    .metric-card .value {
        font-size: 1.6rem;
    }

    .metric-card .label {
        font-size: 12px;
    }

    .why-join {
        padding: 50px 0;
    }

    .value-grid {
        grid-template-columns: 1fr;
    }

    .final-cta {
        padding: 60px 0;
    }

    .final-cta h2 {
        font-size: 1.5rem;
    }

    .landing-hero .cta-buttons,
    .final-cta .cta-buttons {
        flex-direction: column;
    }

    .landing-hero .cta-buttons .btn,
    .final-cta .cta-buttons .btn {
        width: 100%;
    }
}
</style>

<!-- HERO SECTION -->
<section class="landing-hero">
    <div class="wrap landing-hero-inner">
        <h1>Where research on food and climate finds its people</h1>
        <p class="tagline">Connect with researchers, funders, and institutions across the FACT Alliance network. Discover collaborations and funding opportunities powered by AI.</p>

        <div class="cta-buttons">
            <a href="index.php?page=researchers&mode=add" class="btn btn-primary btn-lg">Join as Researcher</a>
            <a href="index.php?page=login" class="btn btn-ghost btn-lg">Log In</a>
        </div>

        <div class="hero-metrics" id="heroMetrics">
            <!-- Populated by JavaScript -->
        </div>
    </div>
</section>

<!-- IMPACT METRICS SECTION WITH TABS -->
<section class="metrics-section">
    <div class="wrap">
        <div class="metrics-header">
            <h2>Alliance Impact</h2>
            <p class="subtitle">Real results from our growing network</p>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-nav" role="tablist">
            <button role="tab" aria-selected="true" aria-controls="tab-network" class="tab-button active" data-tab="network">Network</button>
            <button role="tab" aria-selected="false" aria-controls="tab-funding" class="tab-button" data-tab="funding">Funding</button>
            <button role="tab" aria-selected="false" aria-controls="tab-students" class="tab-button" data-tab="students">Students</button>
            <button role="tab" aria-selected="false" aria-controls="tab-global" class="tab-button" data-tab="global">Global Reach</button>
            <button role="tab" aria-selected="false" aria-controls="tab-platform" class="tab-button" data-tab="platform">Platform</button>
        </div>

        <!-- Tab Content -->
        <div id="tab-network" role="tabpanel" class="tab-content active">
            <div class="metrics-grid" data-category="network">
                <!-- Populated by JavaScript -->
            </div>
        </div>

        <div id="tab-funding" role="tabpanel" class="tab-content">
            <div class="metrics-grid" data-category="funding">
                <!-- Populated by JavaScript -->
            </div>
        </div>

        <div id="tab-students" role="tabpanel" class="tab-content">
            <div class="metrics-grid" data-category="students">
                <!-- Populated by JavaScript -->
            </div>
        </div>

        <div id="tab-global" role="tabpanel" class="tab-content">
            <div class="metrics-grid" data-category="global">
                <!-- Populated by JavaScript -->
            </div>
        </div>

        <div id="tab-platform" role="tabpanel" class="tab-content">
            <div class="metrics-grid" data-category="platform">
                <!-- Populated by JavaScript -->
            </div>
        </div>
    </div>
</section>

<!-- WHY JOIN SECTION -->
<section class="why-join">
    <div class="wrap">
        <h2>Why Join the Alliance</h2>
        <div class="value-grid">
            <div class="value-card">
                <span class="icon">Network</span>
                <h3>Connect Globally</h3>
                <p>Find collaborators and partners across 9 countries working on food security and climate resilience.</p>
            </div>
            <div class="value-card">
                <span class="icon">Funding</span>
                <h3>Discover Opportunities</h3>
                <p>Access 156+ indexed funding calls matched to your research using AI-powered recommendations.</p>
            </div>
            <div class="value-card">
                <span class="icon">Search</span>
                <h3>Intelligent Discovery</h3>
                <p>Use natural language search to find researchers, institutions, and funding aligned with your goals.</p>
            </div>
            <div class="value-card">
                <span class="icon">Collaborate</span>
                <h3>Build Partnerships</h3>
                <p>Communicate directly with researchers and funders. Manage collaborations and proposals in one place.</p>
            </div>
        </div>
    </div>
</section>

<!-- FINAL CTA SECTION -->
<section class="final-cta">
    <div class="wrap final-cta-inner">
        <h2>Ready to Connect?</h2>
        <p>Join the FACT Alliance and be part of a global community advancing food systems and climate science.</p>
        <div class="cta-buttons">
            <a href="index.php?page=researchers&mode=add" class="btn btn-primary btn-lg">Create Your Profile</a>
            <a href="index.php?page=login" class="btn btn-ghost btn-lg">Sign In</a>
        </div>
    </div>
</section>

<script>
(function() {
    // Metrics data from PHP
    const metricsData = <?= json_encode($metricsData) ?>;

    // Render hero metrics
    function renderHeroMetrics() {
        const heroMetrics = document.getElementById('heroMetrics');
        const categories = ['network', 'funding', 'students', 'platform'];
        let html = '';

        categories.forEach(cat => {
            if (metricsData[cat] && metricsData[cat][0]) {
                const m = metricsData[cat][0];
                const val = formatValue(m.metric_value, m.metric_unit);
                html += `
                    <div class="hero-metric">
                        <div class="value" data-target="${m.metric_value}" data-unit="${m.metric_unit || ''}">${val}</div>
                        <div class="label">${m.metric_label}</div>
                    </div>
                `;
            }
        });

        heroMetrics.innerHTML = html;
        animateCounters(heroMetrics);
    }

    // Render all metric cards
    function renderMetrics() {
        Object.keys(metricsData).forEach(category => {
            const grid = document.querySelector(`.metrics-grid[data-category="${category}"]`);
            if (!grid) return;

            let html = '';
            metricsData[category].forEach(m => {
                const val = formatValue(m.metric_value, m.metric_unit);
                html += `
                    <div class="metric-card">
                        <div class="value" data-target="${m.metric_value}" data-unit="${m.metric_unit || ''}">${val}</div>
                        <div class="label">${m.metric_label}</div>
                    </div>
                `;
            });

            grid.innerHTML = html;
        });
    }

    // Format metric value with unit
    function formatValue(val, unit) {
        if (unit === '$') return '$' + (val / 1e6).toFixed(1) + 'M';
        if (unit === '%') return val + '%';
        if (val >= 1e6) return (val / 1e6).toFixed(1) + 'M';
        if (val >= 1e3) return (val / 1e3).toFixed(1) + 'K';
        return val.toString();
    }

    // Animate counter from 0 to target
    function animateCounters(container) {
        const counters = container.querySelectorAll('[data-target]');
        counters.forEach(el => {
            const target = parseInt(el.dataset.target);
            const unit = el.dataset.unit || '';
            const duration = 1500;
            const start = performance.now();

            function frame(now) {
                const progress = Math.min((now - start) / duration, 1);
                const easeOut = 1 - Math.pow(1 - progress, 3);
                const current = Math.floor(target * easeOut);
                el.textContent = formatValue(current, unit);

                if (progress < 1) requestAnimationFrame(frame);
                else el.textContent = formatValue(target, unit);
            }

            requestAnimationFrame(frame);
        });
    }

    // Tab switching
    function setupTabs() {
        const buttons = document.querySelectorAll('.tab-button');
        const panels = document.querySelectorAll('[role="tabpanel"]');

        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                const tabName = btn.dataset.tab;

                // Update buttons
                buttons.forEach(b => {
                    b.classList.remove('active');
                    b.setAttribute('aria-selected', 'false');
                });
                btn.classList.add('active');
                btn.setAttribute('aria-selected', 'true');

                // Update panels
                panels.forEach(p => p.classList.remove('active'));
                document.getElementById(`tab-${tabName}`).classList.add('active');

                // Animate counters in new tab
                const grid = document.querySelector(`[data-category="${tabName}"]`);
                if (grid) animateCounters(grid);
            });
        });
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', () => {
        renderHeroMetrics();
        renderMetrics();
        setupTabs();
    });
})();
</script>
