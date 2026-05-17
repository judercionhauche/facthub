FACT Alliance Hub (PHP MVC-style app)

Setup:
1. Extract folder into htdocs and rename to fact_hub2
2. Start Apache + MySQL in XAMPP
3. Open phpMyAdmin and import database.sql
4. Visit http://localhost/fact_hub2/public
5. Register a user, then log in

Notes:
- This version recreates the Blocks structure with PHP + MySQL.
- Tags are stored as comma-separated text to match your Blocks app design.
- Matching uses the same logic: topic = 2 points, geography = 1 point.
- Styling is intentionally close to the Blocks screenshots: soft cards, green MIT/FACT-inspired theme, sidebar, tabs, tag pills, badges.
