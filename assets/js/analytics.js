/**
 * Analytics & Charts for Agent Angreessen - AI Agent Pay Collector
 * (hardened, lean, collision-safe)
 */
(function (w, d, $) {
  "use strict";

  // Debug mode detection
  const DEBUG_MODE = w.location.hostname === 'localhost' || 
                     w.location.hostname.includes('127.0.0.1') ||
                     w.location.search.includes('debug=true');
  const debugLog = DEBUG_MODE ? console.log.bind(console) : () => {};
  const debugWarn = DEBUG_MODE ? console.warn.bind(console) : () => {};

  if (!w.angreessen49Data || !w.angreessen49Data.ajaxUrl || !w.angreessen49Data.nonce) {
    console.error("[Analytics] Missing angreessen49Data config.");
    return;
  }

  /* ------------------ Config & State ------------------ */
  const DEBUG = !!w.angreessen49Data.debug;
  // Chart.js is now bundled locally and enqueued via wp_enqueue_script
  const COLORS = {
    tx: "#00D091",
    vol: "#8B5CF6",
    buyers: "#3B82F6",
    sellers: "#F59E0B",
  };
  
  const FACILITATOR_COLORS = {
    'Node 1': '#0052FF',
    'Node 2': '#10B981',
    'Node 3': '#F59E0B'
  };
  
  // Map API names to display names
  const FACILITATOR_NAME_MAP = {
    'Coinbase Commerce': 'Node 1',
    'Coinbase': 'Node 1',
    'PayAI': 'Node 2',
    'Daydreams': 'Node 3'
  };
  
  let sparklineCharts = {};
  let facilitatorCharts = {};

  let marketChart = null;
  let analyticsRefreshInterval = null;
  let currentPage = 1;
  const perPage = 10;
  const activeMetrics = {
    transactions: true,
    volume: true,
    buyers: true,
    sellers: true,
  };
  
  // Track last load time to prevent unnecessary reloads
  let lastAnalyticsLoad = 0;
  const CACHE_DURATION = 30000; // 30 seconds

  // Track previous AJAX requests to abort duplicates
  let rqEcosystem = null;
  let rqTopPages = null;
  
  // Single-flight lock for ecosystem requests
  let ecoSeq = 0;

  // Browser-level cache for analytics data (timeframe-specific)
  const ANALYTICS_CACHE_TTL = 120000; // 2 minutes
  
  const getCacheKey = (timeframe) => `angreessen49_analytics_cache_${timeframe}`;

  function getAnalyticsCache(timeframe) {
    try {
      const cached = localStorage.getItem(getCacheKey(timeframe));
      if (!cached) return null;
      const {data, timestamp} = JSON.parse(cached);
      if (Date.now() - timestamp > ANALYTICS_CACHE_TTL) {
        localStorage.removeItem(getCacheKey(timeframe));
        return null;
      }
      debugLog('📊 [Analytics] Using cached', timeframe, 'data from localStorage');
      return data;
    } catch (e) {
      debugWarn('[Analytics] Failed to read cache:', e);
      return null;
    }
  }

  function setAnalyticsCache(timeframe, data) {
    try {
      localStorage.setItem(getCacheKey(timeframe), JSON.stringify({
        data: data,
        timestamp: Date.now()
      }));
      debugLog('📊 [Analytics] Cached', timeframe, 'data to localStorage');
    } catch (e) {
      debugWarn('[Analytics] Failed to cache data:', e);
    }
  }

  /* ------------------ Utilities ------------------ */
  
  // Debounce helper
  const debounce = (fn, ms = 200) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  };

  const log = (...args) => {
    if (DEBUG) console.log("[Analytics]", ...args);
  };

  const ajaxPost = (action, payload = {}) => {
    if (!w.angreessen49Data?.ajaxUrl) return $.Deferred().reject("Missing ajaxUrl").promise();
    return $.ajax({
      url: w.angreessen49Data.ajaxUrl,
      type: "POST",
      dataType: "json",
      timeout: 10000,
      data: { action, nonce: w.angreessen49Data.nonce, ...payload },
    });
  };

  const esc = (s) =>
    String(s ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");

  const nf = new Intl.NumberFormat("en-US");
  const cf = new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  const formatNumber = (num) => {
    const n = Number(num || 0);
    if (!Number.isFinite(n)) return "0";
    return nf.format(n);
  };

  const formatLargeNumber = (num) => {
    const n = Number(num || 0);
    if (!Number.isFinite(n)) return "0";
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + "M";
    if (n >= 1_000) return (n / 1_000).toFixed(1) + "K";
    return nf.format(n);
  };

  const formatCurrency = (amount) => {
    const n = Number(amount || 0);
    if (!Number.isFinite(n)) return "$0.00";
    
    // Ecosystem API returns values already in USDC - no conversion needed
    const dollars = n;
    
    // Show M for millions
    if (dollars >= 1_000_000) {
      const millions = dollars / 1_000_000;
      return "$" + millions.toFixed(2) + "M";
    }
    // Show K for thousands
    if (dollars >= 1_000) {
      const thousands = dollars / 1_000;
      return "$" + thousands.toFixed(2) + "K";
    }
    // Show full amount for under $1000
    return cf.format(dollars);
  };

  // Facilitators API returns amounts in micro-USDC (1e-6), need to convert
  const formatCurrencyFromMicro = (microAmount) => {
    const n = Number(microAmount || 0);
    if (!Number.isFinite(n)) return "$0.00";
    
    // Convert micro-USDC to USDC (divide by 1,000,000)
    const dollars = n / 1_000_000;
    
    // Show M for millions
    if (dollars >= 1_000_000) {
      const millions = dollars / 1_000_000;
      return "$" + millions.toFixed(2) + "M";
    }
    // Show K for thousands
    if (dollars >= 1_000) {
      const thousands = dollars / 1_000;
      return "$" + thousands.toFixed(2) + "K";
    }
    // Show full amount for under $1000
    return cf.format(dollars);
  };

  const formatMoney = (amount) => {
    const n = Number(amount || 0);
    return Number.isFinite(n) ? n.toFixed(3) : "0.000";
  };

  const formatDate = (dateStr) => {
    const dt = new Date(dateStr);
    if (Number.isNaN(+dt)) return esc(String(dateStr || ""));
    const month = dt.toLocaleString("en-US", { month: "short" });
    const day = dt.getDate();
    return `${month} ${day}`;
  };

  const safeLink = (href, text) => `<a href="${esc(href)}" target="_blank" rel="noopener">${esc(text)}</a>`;

  /* ------------------ Chart.js loader (idempotent) ------------------ */

  function ensureChartJS(cb) {
    // Chart.js is now bundled locally and enqueued via wp_enqueue_script
    // Just check if it's loaded yet
    if (typeof w.Chart !== "undefined") {
      return cb?.();
    }
    
    // Poll for Chart.js since it's enqueued and should load shortly
    const pollInterval = setInterval(() => {
      if (typeof w.Chart !== "undefined") {
        debugLog('✅ Chart.js detected (bundled locally)');
        clearInterval(pollInterval);
        cb?.();
      }
    }, 50); // Check every 50ms
    
    // Clear polling after 5 seconds to prevent infinite loop
    setTimeout(() => {
      clearInterval(pollInterval);
      if (typeof w.Chart === "undefined") {
        console.error("🔴 Chart.js failed to load - check wp_enqueue_script");
      }
    }, 5000);
  }

  /* ------------------ DOM Ready ------------------ */

  $(d).ready(function () {
    log("Initializing analytics module");

    // Preload Chart.js (non-blocking)
    ensureChartJS();

    // If analytics tab is already active on load
    if ($('[data-tab="analytics"]').hasClass("active")) {
      loadAnalyticsData();
      loadTopPages();
      loadFacilitatorData();
      startAnalyticsAutoRefresh();
    }

    // Tab switch
    $(d).on("click", '[data-tab="analytics"]', function () {
      const now = Date.now();
      const timeSinceLastLoad = now - lastAnalyticsLoad;
      
      // Only reload if data is stale (older than 30 seconds)
      if (timeSinceLastLoad > CACHE_DURATION) {
        // Slight defer to allow tab DOM to paint
        setTimeout(loadAnalyticsData, 60);
        setTimeout(loadTopPages, 60);
        setTimeout(loadFacilitatorData, 60);
      }
      startAnalyticsAutoRefresh();
    });

    // Timeframe changes
    $(d).on("change", "#analytics-timeframe", function () {
      loadAnalyticsData();
      loadTopPages();
      loadFacilitatorData();
    });

    // Metric toggles
    $(d).on("click", ".metric-toggle", function () {
      const metric = $(this).data("metric");
      if (!Object.prototype.hasOwnProperty.call(activeMetrics, metric)) return;
      $(this).toggleClass("active");
      activeMetrics[metric] = $(this).hasClass("active");
      // re-render with current data set
      loadAnalyticsData();
    });

    // Pagination
    $(d).on("click", ".page-btn", function () {
      const page = parseInt($(this).data("page"), 10);
      if (Number.isFinite(page)) loadTopPages(page);
    });

    // Pause auto-refresh if hidden
    $(d).on("visibilitychange", function () {
      if (d.visibilityState === "hidden" && analyticsRefreshInterval) {
        clearInterval(analyticsRefreshInterval);
        analyticsRefreshInterval = null;
      } else if (d.visibilityState === "visible" && $('[data-tab="analytics"]').hasClass("active")) {
        startAnalyticsAutoRefresh();
      }
    });
  });

  /* ------------------ API Calls ------------------ */

  function loadAnalyticsData() {
    // Set timestamp IMMEDIATELY to prevent duplicate calls
    lastAnalyticsLoad = Date.now();
    
    const timeframe = $("#analytics-timeframe").val() || "30d";
    
    // Try browser cache first (show immediately, then fetch fresh in background)
    const cachedData = getAnalyticsCache(timeframe);
    if (cachedData) {
      renderFromCache(cachedData);
      // Continue to fetch fresh data in background
    }
    
    // Show loading overlay
    showAnalyticsLoading();
    
    // REMOVED: Redundant angreessen49_get_analytics call
    // Data is fetched directly from ecosystem-data.php and top-pages endpoints
    
    // Load ecosystem stats directly
    loadEcosystemData(timeframe);
    
    // Load top pages
    loadTopPages(1);
  }

  function renderFromCache(data) {
    debugLog("📊 [Analytics] Rendering from cache:", data);
    
    if (data.ecosystem) {
      const { unique_buyers, unique_sellers, total_transactions, total_amount } = data.ecosystem;
      $("#stat-ecosystem-buyers").text(formatLargeNumber(unique_buyers || 0));
      $("#stat-ecosystem-sellers").text(formatLargeNumber(unique_sellers || 0));
      $("#stat-ecosystem-transactions").text(formatLargeNumber(total_transactions || 0));
      $("#stat-market-revenue").text(formatCurrency(total_amount || 0));
      
      if (data.ecosystem.bucketed_data?.length) {
        renderMarketOverviewChart(data.ecosystem.bucketed_data);
      }
    }
    
    hideAnalyticsLoading();
  }

  function showAnalyticsLoading() {
    $(".stat-value").addClass("skeleton-loading");
  }

  function hideAnalyticsLoading() {
    $(".stat-value").removeClass("skeleton-loading");
  }

  function loadEcosystemData(timeframe) {
    const seq = ++ecoSeq;  // Single-flight lock
    
    const $buyers = $("#stat-ecosystem-buyers");
    const $sellers = $("#stat-ecosystem-sellers");
    const $transactions = $("#stat-ecosystem-transactions");
    const $revenue = $("#stat-market-revenue");
    
    // Show loading state
    $buyers.text("...");
    $sellers.text("...");
    $transactions.text("...");
    $revenue.text("...");
    
    // Abort previous request if exists
    if (rqEcosystem && rqEcosystem.abort) {
      rqEcosystem.abort();
    }
    
    const ajaxUrl = w.angreessen49Data.pluginUrl + 'ecosystem-data.php';
    
    rqEcosystem = $.ajax({
      url: ajaxUrl,
      method: 'POST',
      data: { 
        timeframe: timeframe,
        nonce: w.angreessen49Data.nonce
      },
      timeout: 10000,  // Reduced timeout with PHP fallback
      success: function(response) {
        // Ignore stale responses
        if (seq !== ecoSeq) {
          return;
        }
        
        if (response.success && response.data) {
          const data = response.data;
          
          // Update DOM with values
          $buyers.text(formatLargeNumber(data.unique_buyers || 0));
          $sellers.text(formatLargeNumber(data.unique_sellers || 0));
          $transactions.text(formatLargeNumber(data.total_transactions || 0));
          $revenue.text(formatCurrency(data.total_amount || 0));
          
          // Cache the data with timeframe
          setAnalyticsCache(timeframe, { ecosystem: data });
          
          // Render sparklines if we have bucketed data
          if (data.bucketed_data && data.bucketed_data.length) {
            try {
              renderSparklines(data.bucketed_data);
            } catch (err) {
              console.error("[ECOSYSTEM] renderSparklines() error:", err);
            }
            
            try {
              renderMarketOverviewChart(data.bucketed_data);
            } catch (err) {
              console.error("[ECOSYSTEM] renderMarketOverviewChart() error:", err);
            }
          }
        } else {
          // Soft failure: prefer cache over error
          const cached = getAnalyticsCache(timeframe);
          if (cached?.ecosystem) {
            const data = cached.ecosystem;
            $buyers.text(formatLargeNumber(data.unique_buyers || 0));
            $sellers.text(formatLargeNumber(data.unique_sellers || 0));
            $transactions.text(formatLargeNumber(data.total_transactions || 0));
            $revenue.text(formatCurrency(data.total_amount || 0));
            if (data.bucketed_data?.length) {
              renderMarketOverviewChart(data.bucketed_data);
            }
          } else {
            // Only show error if NO cache exists
            $buyers.text("—");
            $sellers.text("—");
            $transactions.text("—");
            $revenue.text("—");
          }
        }
        
        hideAnalyticsLoading();
      },
      error: function(xhr, status, error) {
        // Ignore stale responses
        if (seq !== ecoSeq) {
          debugLog("⚪ [ECOSYSTEM] Ignoring stale error seq:", seq);
          return;
        }
        
        // Don't log aborts as errors
        if (status === 'abort') {
          debugLog("⚪ [ECOSYSTEM] Request aborted (expected on tab/timeframe change)");
          hideAnalyticsLoading();
          return;
        }
        
        console.error("🔴 [ECOSYSTEM] Request failed:", status, error);
        
        // Prefer cache over error
        const cached = getAnalyticsCache(timeframe);
        if (cached?.ecosystem) {
          debugWarn("⚠️ [ECOSYSTEM] Using cached data (network error fallback)");
          const data = cached.ecosystem;
          $buyers.text(formatLargeNumber(data.unique_buyers || 0));
          $sellers.text(formatLargeNumber(data.unique_sellers || 0));
          $transactions.text(formatLargeNumber(data.total_transactions || 0));
          $revenue.text(formatCurrency(data.total_amount || 0));
          if (data.bucketed_data?.length) {
            renderMarketOverviewChart(data.bucketed_data);
          }
        } else {
          // Only show error if NO cache exists
          $buyers.text("—");
          $sellers.text("—");
          $transactions.text("—");
          $revenue.text("—");
        }
        
        hideAnalyticsLoading();
      }
    });
  }

  function loadTopPages(page = 1) {
    debugLog("📄 [TopPages] ==================== LOAD TOP PAGES START ====================");
    debugLog("📄 [TopPages] Page:", page);
    
    currentPage = page;
    const offset = (page - 1) * perPage;
    const timeframe = $("#analytics-timeframe").val() || "30d";
    const requestData = { timeframe, limit: perPage, offset };

    debugLog("📄 [TopPages] Request data:", requestData);

    // abort stale request
    if (rqTopPages && rqTopPages.abort) {
      rqTopPages.abort();
    }

    rqTopPages = ajaxPost("angreessen49_get_top_pages", requestData)
      .done(function (res) {
        debugLog("📄 [TopPages] Response:", res);
        if (res?.success && res.data) {
          renderTopPages(res.data.pages || [], res.data.total || 0);
        } else {
          debugWarn("📄 [TopPages] No data in response");
          renderTopPages([], 0);
        }
      })
      .fail(function (xhr, status, error) {
        if (status === 'abort') {
          debugLog("📄 [TopPages] Request aborted");
          return;
        }
        console.error("📄 [TopPages] Request failed:", status, error);
        renderTopPages([], 0);
      });
  }

  function loadFacilitatorData() {
    const timeframe = $("#analytics-timeframe").val() || "30d";
    
    debugLog("🏭 [Facilitators] Loading data for timeframe:", timeframe);
    
    const ajaxUrl = w.angreessen49Data.pluginUrl + 'facilitators-data.php';
    
    $.ajax({
      url: ajaxUrl,
      method: 'POST',
      data: { 
        timeframe: timeframe,
        nonce: w.angreessen49Data.nonce
      },
      timeout: 10000,
      success: function(response) {
        debugLog("🏭 [Facilitators] Response:", response);
        
        if (response.success && response.data && response.data.facilitators) {
          renderFacilitatorData(response.data.facilitators, response.data.totals);
        } else {
          debugWarn("🏭 [Facilitators] No data in response");
          renderFacilitatorData([], null);
        }
      },
      error: function(xhr, status, error) {
        console.error("🏭 [Facilitators] Request failed:", status, error);
        renderFacilitatorData([], null);
      }
    });
  }

  function startAnalyticsAutoRefresh() {
    // Clear any existing interval
    if (analyticsRefreshInterval) {
      clearInterval(analyticsRefreshInterval);
    }
    
    // Auto-refresh every 5 minutes
    analyticsRefreshInterval = setInterval(function() {
      if ($('[data-tab="analytics"]').hasClass("active")) {
        loadAnalyticsData();
      }
    }, 300000); // 5 minutes
  }

  /* ------------------ Rendering Functions ------------------ */

  function renderTopPages(pages, total) {
    const $container = $("#top-pages-list");
    const $pagination = $("#top-pages-pagination");
    
    if (!$container.length) {
      debugWarn("📄 [TopPages] Container #top-pages-list not found");
      return;
    }
    
    $container.empty();
    
    if (!pages || pages.length === 0) {
      $container.html('<div class="no-data-message">No page data available for this timeframe</div>');
      $pagination.empty();
      return;
    }
    
    pages.forEach(function(page, index) {
      const rank = (currentPage - 1) * perPage + index + 1;
      const $row = $(`
        <div class="top-page-row">
          <span class="page-rank">#${rank}</span>
          <div class="page-info">
            <a href="${esc(page.url || '#')}" target="_blank" class="page-title">${esc(page.title || 'Untitled')}</a>
            <span class="page-url">${esc(page.domain || '')}</span>
          </div>
          <div class="page-stats">
            <span class="stat-transactions">${formatNumber(page.transactions || 0)} txns</span>
            <span class="stat-revenue">${formatCurrency(page.revenue || 0)}</span>
          </div>
        </div>
      `);
      $container.append($row);
    });
    
    // Render pagination
    const totalPages = Math.ceil(total / perPage);
    if (totalPages > 1) {
      let paginationHtml = '<div class="pagination-controls">';
      
      if (currentPage > 1) {
        paginationHtml += `<button class="page-btn" data-page="${currentPage - 1}">← Prev</button>`;
      }
      
      paginationHtml += `<span class="page-info">Page ${currentPage} of ${totalPages}</span>`;
      
      if (currentPage < totalPages) {
        paginationHtml += `<button class="page-btn" data-page="${currentPage + 1}">Next →</button>`;
      }
      
      paginationHtml += '</div>';
      $pagination.html(paginationHtml);
    } else {
      $pagination.empty();
    }
  }

  function renderSparklines(bucketedData) {
    if (!bucketedData || bucketedData.length === 0) return;
    
    ensureChartJS(function() {
      const labels = bucketedData.map(d => formatDate(d.bucket_date || d.date));
      
      // Transactions sparkline
      renderSparkline('sparkline-transactions', labels, 
        bucketedData.map(d => d.transactions || 0), COLORS.tx);
      
      // Volume sparkline
      renderSparkline('sparkline-volume', labels, 
        bucketedData.map(d => d.total_amount || 0), COLORS.vol);
      
      // Buyers sparkline
      renderSparkline('sparkline-buyers', labels, 
        bucketedData.map(d => d.unique_buyers || 0), COLORS.buyers);
      
      // Sellers sparkline
      renderSparkline('sparkline-sellers', labels, 
        bucketedData.map(d => d.unique_sellers || 0), COLORS.sellers);
    });
  }

  function renderSparkline(canvasId, labels, data, color) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    // Destroy existing chart if any
    if (sparklineCharts[canvasId]) {
      sparklineCharts[canvasId].destroy();
    }
    
    sparklineCharts[canvasId] = new w.Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          data: data,
          borderColor: color,
          borderWidth: 2,
          fill: true,
          backgroundColor: color + '20',
          tension: 0.4,
          pointRadius: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { enabled: false }
        },
        scales: {
          x: { display: false },
          y: { display: false }
        },
        elements: {
          line: { borderCapStyle: 'round' }
        }
      }
    });
  }

  function renderMarketOverviewChart(bucketedData) {
    if (!bucketedData || bucketedData.length === 0) return;
    
    ensureChartJS(function() {
      const canvas = document.getElementById('market-overview-chart');
      if (!canvas) return;
      
      const ctx = canvas.getContext('2d');
      
      // Destroy existing chart if any
      if (marketChart) {
        marketChart.destroy();
      }
      
      const labels = bucketedData.map(d => formatDate(d.bucket_date || d.date));
      
      const datasets = [];
      
      if (activeMetrics.transactions) {
        datasets.push({
          label: 'Transactions',
          data: bucketedData.map(d => d.transactions || 0),
          borderColor: COLORS.tx,
          backgroundColor: COLORS.tx + '20',
          yAxisID: 'y-count',
          tension: 0.4
        });
      }
      
      if (activeMetrics.volume) {
        datasets.push({
          label: 'Volume (USD)',
          data: bucketedData.map(d => d.total_amount || 0),
          borderColor: COLORS.vol,
          backgroundColor: COLORS.vol + '20',
          yAxisID: 'y-volume',
          tension: 0.4
        });
      }
      
      if (activeMetrics.buyers) {
        datasets.push({
          label: 'Buyers',
          data: bucketedData.map(d => d.unique_buyers || 0),
          borderColor: COLORS.buyers,
          backgroundColor: COLORS.buyers + '20',
          yAxisID: 'y-count',
          tension: 0.4
        });
      }
      
      if (activeMetrics.sellers) {
        datasets.push({
          label: 'Sellers',
          data: bucketedData.map(d => d.unique_sellers || 0),
          borderColor: COLORS.sellers,
          backgroundColor: COLORS.sellers + '20',
          yAxisID: 'y-count',
          tension: 0.4
        });
      }
      
      marketChart = new w.Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false
          },
          plugins: {
            legend: {
              position: 'top',
              labels: { usePointStyle: true }
            }
          },
          scales: {
            x: {
              grid: { display: false }
            },
            'y-count': {
              type: 'linear',
              position: 'left',
              beginAtZero: true,
              grid: { color: '#e5e7eb' }
            },
            'y-volume': {
              type: 'linear',
              position: 'right',
              beginAtZero: true,
              grid: { display: false },
              ticks: {
                callback: function(value) {
                  return formatCurrency(value);
                }
              }
            }
          }
        }
      });
    });
  }

  function renderFacilitatorData(facilitators, totals) {
    const $container = $("#facilitators-list");
    const $chart = $("#facilitators-chart");
    
    if (!$container.length) {
      debugWarn("🏭 [Facilitators] Container not found");
      return;
    }
    
    $container.empty();
    
    if (!facilitators || facilitators.length === 0) {
      $container.html('<div class="no-data-message">No facilitator data available</div>');
      return;
    }
    
    // Render facilitator cards
    facilitators.forEach(function(fac) {
      const displayName = FACILITATOR_NAME_MAP[fac.name] || fac.name;
      const color = FACILITATOR_COLORS[displayName] || '#6B7280';
      
      const $card = $(`
        <div class="facilitator-card" style="border-left: 4px solid ${color}">
          <div class="facilitator-header">
            <span class="facilitator-name">${esc(displayName)}</span>
            <span class="facilitator-badge" style="background: ${color}20; color: ${color}">${formatNumber(fac.transactions || 0)} txns</span>
          </div>
          <div class="facilitator-stats">
            <div class="fac-stat">
              <span class="fac-stat-value">${formatCurrencyFromMicro(fac.total_amount || 0)}</span>
              <span class="fac-stat-label">Volume</span>
            </div>
            <div class="fac-stat">
              <span class="fac-stat-value">${formatNumber(fac.unique_buyers || 0)}</span>
              <span class="fac-stat-label">Buyers</span>
            </div>
            <div class="fac-stat">
              <span class="fac-stat-value">${formatNumber(fac.unique_sellers || 0)}</span>
              <span class="fac-stat-label">Sellers</span>
            </div>
          </div>
        </div>
      `);
      $container.append($card);
    });
    
    // Render pie chart if chart container exists
    if ($chart.length && facilitators.length > 0) {
      renderFacilitatorChart(facilitators);
    }
  }

  function renderFacilitatorChart(facilitators) {
    ensureChartJS(function() {
      const canvas = document.getElementById('facilitators-pie-chart');
      if (!canvas) return;
      
      const ctx = canvas.getContext('2d');
      
      // Destroy existing chart
      if (facilitatorCharts['pie']) {
        facilitatorCharts['pie'].destroy();
      }
      
      const labels = facilitators.map(f => FACILITATOR_NAME_MAP[f.name] || f.name);
      const data = facilitators.map(f => f.transactions || 0);
      const colors = labels.map(name => FACILITATOR_COLORS[name] || '#6B7280');
      
      facilitatorCharts['pie'] = new w.Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: labels,
          datasets: [{
            data: data,
            backgroundColor: colors,
            borderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
              labels: { usePointStyle: true }
            }
          }
        }
      });
    });
  }

})(window, document, jQuery);
