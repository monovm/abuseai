import './bootstrap';

// Chart.js bundled via Vite — exposes window.Chart so admin pages
// (dashboard, analytics) can use the same `new Chart(...)` calls
// they used when loading from the CDN, without the SRI/availability risk.
import Chart from 'chart.js/auto';
window.Chart = Chart;
