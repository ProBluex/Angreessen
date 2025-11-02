/**
 * Analytics & Charts for Tolliver - AI Agent Pay Collector
 * (hardened, lean, collision-safe)
 */
(function (w, d, $) {
  "use strict";

  if (!w.agentHubData || !w.agentHubData.ajaxUrl || !w.agentHubData.nonce) {
    console.error("[Analytics] Missing agentHubData config.");
    return;
  }

  /* ------------------ Config & State ------------------ */
  const DEBUG = !!w.agentHubData.debug;
  const CHARTJS_SRC = "https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js";
  const COLORS = {
    tx: "#00D091",
    vol: "#8B5CF6",
    buyers: "#3B82F6",
    sellers: "#F59E0B",
  };

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

  // Browser-level cache for analytics data
  const ANALYTICS_CACHE_KEY = 'agent_hub_analytics_cache';
  const ANALYTICS_CACHE_TTL = 300000; // 5 minutes

  function getAnalyticsCache() {
    try {
      const cached = localStorage.getItem(ANALYTICS_CACHE_KEY);
      if (!cached) return null;
      const {data, timestamp} = JSON.parse(cached);
      if (Date.now() - timestamp > ANALYTICS_CACHE_TTL) {
        localStorage.removeItem(ANALYTICS_CACHE_KEY);
        return null;
      }
      console.log('📊 [Analytics] Using cached data from localStorage');
      return data;
    } catch (e) {
      console.warn('[Analytics] Failed to read cache:', e);
      return null;
    }
  }

  function setAnalyticsCache(data) {
    try {
      localStorage.setItem(ANALYTICS_CACHE_KEY, JSON.stringify({
        data: data,
        timestamp: Date.now()
      }));
      console.log('📊 [Analytics] Data cached to localStorage');
    } catch (e) {
      console.warn('[Analytics] Failed to cache data:', e);
    }
  }

  /* ------------------ Utilities ------------------ */

  const log = (...args) => {
    if (DEBUG) console.log("[Analytics]", ...args);
  };

  const ajaxPost = (action, payload = {}) => {
    if (!w.agentHubData?.ajaxUrl) return $.Deferred().reject("Missing ajaxUrl").promise();
    return $.ajax({
      url: w.agentHubData.ajaxUrl,
      type: "POST",
      dataType: "json",
      timeout: 15000,
      data: { action, nonce: w.agentHubData.nonce, ...payload },
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
    if (!Number.isFinite(n)) return "$0.000";
    if (n >= 1_000_000) return "$" + (n / 1_000_000).toFixed(1) + "M";
    if (n >= 1_000) return "$" + (n / 1_000).toFixed(1) + "K";
    return cf.format(n);
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
    if (typeof w.Chart !== "undefined") return cb?.();
    if (d.getElementById("chartjs-umd")) return d.getElementById("chartjs-umd").addEventListener("load", () => cb?.());
    const s = d.createElement("script");
    s.id = "chartjs-umd";
    s.src = CHARTJS_SRC;
    s.onload = () => {
      log("Chart.js loaded");
      cb?.();
    };
    s.onerror = () => console.error("[Analytics] Failed to load Chart.js");
    d.head.appendChild(s);
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
      }
      startAnalyticsAutoRefresh();
    });

    // Timeframe changes
    $(d).on("change", "#analytics-timeframe", function () {
      loadAnalyticsData();
      loadTopPages();
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
    console.log("📊 [Analytics] ==================== LOAD ANALYTICS START ====================");
    console.log("📊 [Analytics] Timestamp:", new Date().toISOString());
    
    // Set timestamp IMMEDIATELY to prevent duplicate calls
    lastAnalyticsLoad = Date.now();
    
    // Try browser cache first
    const cachedData = getAnalyticsCache();
    if (cachedData) {
      renderFromCache(cachedData);
      return;
    }
    
    const timeframe = $("#analytics-timeframe").val() || "30d";
    console.log("📊 [Analytics] Selected timeframe:", timeframe);
    
    // Show loading overlay
    showAnalyticsLoading();
    
    // REMOVED: Redundant agent_hub_get_analytics call
    // Data is fetched directly from ecosystem-data.php and top-pages endpoints
    
    // Load ecosystem stats directly
    loadEcosystemData(timeframe);
    
    // Load top pages
    loadTopPages(1);
  }

  function renderFromCache(data) {
    console.log("📊 [Analytics] Rendering from cache:", data);
    
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
    console.log("🌍 [ECOSYSTEM] ==================== DIRECT CALL START ====================");
    console.log("🌍 [ECOSYSTEM] Plugin URL:", w.agentHubData.pluginUrl);
    console.log("🌍 [ECOSYSTEM] Full endpoint:", w.agentHubData.pluginUrl + 'ecosystem-data.php');
    console.log("🌍 [ECOSYSTEM] Request data:", { timeframe });
    
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
      console.log("⚪ [ECOSYSTEM] Aborting previous ecosystem request");
      rqEcosystem.abort();
    }
    
    console.log("🌍 [ECOSYSTEM] Sending AJAX request...");
    
    rqEcosystem = $.ajax({
      url: w.agentHubData.pluginUrl + 'ecosystem-data.php',
      method: 'POST',
      data: { 
        timeframe: timeframe,
        nonce: w.agentHubData.nonce
      },
      timeout: 20000,  // Extended timeout for ecosystem-wide queries
            success: function(response) {
                console.log("✅ [ECOSYSTEM] Response received");
                console.log("🌍 [ECOSYSTEM] Raw response type:", typeof response);
                console.log("🌍 [ECOSYSTEM] Response keys:", Object.keys(response));
                console.log("🌍 [ECOSYSTEM] Response success:", response.success);
                console.log("🌍 [ECOSYSTEM] Response has data:", !!response.data);
        
        if (response.success && response.data) {
          const data = response.data;
          
          // Always update DOM with valid data (including zeros for new sites)
          const formattedBuyers = formatLargeNumber(data.unique_buyers || 0);
          const formattedSellers = formatLargeNumber(data.unique_sellers || 0);
          const formattedTransactions = formatLargeNumber(data.total_transactions || 0);
          const formattedRevenue = formatCurrency(data.total_amount || 0);
          
          console.log("🌍 [ECOSYSTEM] Formatted values:", {
            buyers: formattedBuyers,
            sellers: formattedSellers,
            transactions: formattedTransactions,
            revenue: formattedRevenue
          });
          
          $buyers.text(formattedBuyers);
          $sellers.text(formattedSellers);
          $transactions.text(formattedTransactions);
          $revenue.text(formattedRevenue);
          
          // Cache the data
          setAnalyticsCache({ ecosystem: data });
          
          console.log("✅ [ECOSYSTEM] DOM updated successfully");
          
          // Update chart if we have bucketed data
          if (data.bucketed_data && data.bucketed_data.length) {
            console.log("🌍 [ECOSYSTEM] Rendering chart with", data.bucketed_data.length, "buckets");
            renderMarketOverviewChart(data.bucketed_data);
          }
        } else {
          console.error("🔴 [ECOSYSTEM] Request failed or no data");
          console.error("🔴 [ECOSYSTEM] Full response:", response);
          if (response.message) {
            console.error("🔴 [ECOSYSTEM] Error message:", response.message);
          }
          
          // Show user-friendly error in UI
          $buyers.text("Error");
          $sellers.text("Error");
          $transactions.text("Error");
          $revenue.text("Error");
        }
        
        hideAnalyticsLoading();
      },
      error: function(xhr, status, error) {
        console.error("🔴 [ECOSYSTEM] Request failed");
        console.error("🔴 [ECOSYSTEM] Status:", status);
        console.error("🔴 [ECOSYSTEM] Error:", error);
        console.error("🔴 [ECOSYSTEM] XHR status:", xhr.status);
        
        // If timeout and this is first attempt, retry once with longer timeout
        if (status === 'timeout' && !this.retried) {
          console.warn("⚠️ [ECOSYSTEM] Timeout occurred, retrying once...");
          this.retried = true;
          setTimeout(function() {
            loadEcosystemData(timeframe);
          }, 1000);
          return;
        }
        
        // Show user-friendly error
        $buyers.text(status === 'timeout' ? "Timeout" : "Error");
        $sellers.text(status === 'timeout' ? "Timeout" : "Error");
        $transactions.text(status === 'timeout' ? "Timeout" : "Error");
        $revenue.text(status === 'timeout' ? "Timeout" : "Error");
        
        hideAnalyticsLoading();
      }
    });
  }

  function loadTopPages(page = 1) {
    console.log("📄 [TopPages] ==================== LOAD TOP PAGES START ====================");
    console.log("📄 [TopPages] Page:", page);
    
    currentPage = page;
    const offset = (page - 1) * perPage;
    const timeframe = $("#analytics-timeframe").val() || "30d";
    const requestData = { timeframe, limit: perPage, offset };

    console.log("📄 [TopPages] Request data:", requestData);

    // abort stale request
    if (rqTopPages && rqTopPages.abort) {
      console.log("⚪ [TopPages] Aborting previous top pages request");
      rqTopPages.abort();
    }

    console.log("📄 [TopPages] Making AJAX request to agent_hub_get_top_pages");
    rqTopPages = ajaxPost("agent_hub_get_top_pages", requestData)
      .done((res) => {
        console.log("✅ [TopPages] Response received");
        console.log("📄 [TopPages] Response success:", res?.success);
        console.log("📄 [TopPages] Response has data:", !!res?.data);
        
        if (res?.success && res.data) {
          const pages = res.data.pages || [];
          const total = Number(res.data.total || 0);
          console.log("📄 [TopPages] Pages count:", pages.length);
          console.log("📄 [TopPages] Total pages:", total);
          
          renderTopContent(pages);
          renderPagination(total, currentPage, perPage);
          console.log("✅ [TopPages] Rendering completed");
        } else {
          console.warn("⚠️ [TopPages] No pages found in response");
          $("#top-content-body").html(
            '<tr><td colspan="2" style="text-align:center; color:#666;">No pages found</td></tr>',
          );
          $("#top-content-pagination").hide();
        }
      })
      .fail((xhr, status, error) => {
        console.log("🔴 [TopPages] Request failed:", {
          status: status,
          error: error,
          xhr_status: xhr?.status,
          xhr_statusText: xhr?.statusText,
          is_abort: status === 'abort'
        });
        
        // Don't show errors for intentional aborts
        if (status === 'abort') {
          console.log("⚪ [TopPages] Request aborted (normal when switching timeframes)");
          return;
        }
        
        console.error("❌ [TopPages] Real error:", status, error, xhr?.responseText);
        $("#top-content-body").html(
          '<tr><td colspan="2" style="text-align:center; color:#c00;">Failed to load top pages</td></tr>',
        );
        $("#top-content-pagination").hide();
      });
  }

  /* ------------------ Rendering ------------------ */

  function renderAnalytics(data) {
    log("Rendering analytics dashboard");

    // NOTE: Ecosystem stats are now handled by direct ecosystem-data.php call
    // This function is kept for backwards compatibility and site-specific data
    // Only render chart if site data has bucketed_data
    
    const site = data.site || {};
    const series = Array.isArray(site.bucketed_data) ? site.bucketed_data : [];
    
    if (series.length) {
      renderMarketOverviewChart(series);
    } else {
      showEmptyChartState();
    }
  }

  function renderMarketOverviewChart(bucketedData) {
    const canvas = d.getElementById("market-chart");
    if (!canvas) {
      console.warn("[Analytics] Market chart canvas not found");
      return;
    }

    // Wait if Chart.js not ready yet
    if (typeof w.Chart === "undefined") {
      log("Chart.js not ready; retrying...");
      return setTimeout(() => renderMarketOverviewChart(bucketedData), 200);
    }

    // Destroy previous chart to prevent leaks
    if (marketChart) marketChart.destroy();

    const labels = bucketedData.map((d) => formatDate(d.date));
    const datasets = [];

    const coalesce = (v) => {
      const n = Number(v || 0);
      return Number.isFinite(n) ? n : 0;
    };

    if (activeMetrics.transactions) {
      datasets.push({
        label: "Transactions",
        data: bucketedData.map((d) => coalesce(d.transactions)),
        borderColor: COLORS.tx,
        backgroundColor: "rgba(0, 208, 145, 0.10)",
        yAxisID: "y",
        tension: 0.35,
        pointRadius: 0,
      });
    }
    if (activeMetrics.volume) {
      datasets.push({
        label: "Volume (USDC)",
        data: bucketedData.map((d) => coalesce(d.volume)),
        borderColor: COLORS.vol,
        backgroundColor: "rgba(139, 92, 246, 0.10)",
        yAxisID: "y1",
        tension: 0.35,
        pointRadius: 0,
      });
    }
    if (activeMetrics.buyers) {
      datasets.push({
        label: "Buyers",
        data: bucketedData.map((d) => coalesce(d.buyers)),
        borderColor: COLORS.buyers,
        backgroundColor: "rgba(59, 130, 246, 0.10)",
        yAxisID: "y",
        tension: 0.35,
        pointRadius: 0,
      });
    }
    if (activeMetrics.sellers) {
      datasets.push({
        label: "Sellers",
        data: bucketedData.map((d) => coalesce(d.sellers)),
        borderColor: COLORS.sellers,
        backgroundColor: "rgba(245, 158, 11, 0.10)",
        yAxisID: "y",
        tension: 0.35,
        pointRadius: 0,
      });
    }

    $("#market-chart-container").show();
    $(".chart-empty-state").hide();

    marketChart = new w.Chart(canvas, {
      type: "line",
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        plugins: {
          legend: { display: true, position: "top" },
          tooltip: { backgroundColor: "rgba(0,0,0,0.8)", padding: 12 },
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { maxRotation: 45, minRotation: 45 },
          },
          y: {
            type: "linear",
            display: true,
            position: "left",
            title: { display: true, text: "Count" },
            beginAtZero: true,
          },
          y1: {
            type: "linear",
            display: true,
            position: "right",
            title: { display: true, text: "Volume (USDC)" },
            beginAtZero: true,
            grid: { drawOnChartArea: false },
          },
        },
      },
    });
  }

  function showEmptyChartState() {
    $("#market-chart-container").hide();
    $(".chart-empty-state")
      .show()
      .html(
        '<p style="text-align:center; color:#666; padding:60px 20px;">No ecosystem data available yet. Check back soon!</p>',
      );
  }

  function renderTopContent(pages) {
    const $tbody = $("#top-content-body");
    $tbody.empty();

    if (!Array.isArray(pages) || pages.length === 0) {
      $tbody.html('<tr><td colspan="2" style="text-align:center; color:#666;">No pages found</td></tr>');
      return;
    }

    const rows = pages
      .map((p) => {
        const url = p.url || p.page_url || "#";
        const title = p.title || p.page_title || "Untitled";
        const revenue = p.revenue ?? p.agent_revenue ?? 0;
        return `
        <tr>
          <td>${safeLink(url, title)}</td>
          <td>$${formatMoney(revenue)}</td>
        </tr>`;
      })
      .join("");

    $tbody.html(rows);
  }

  function renderPagination(total, page, size) {
    const $container = $("#top-content-pagination");
    const totalPages = Math.max(1, Math.ceil(Number(total || 0) / size));

    if (totalPages <= 1) {
      $container.hide();
      return;
    }

    $container.show().empty();

    let html = '<div class="pagination">';

    if (page > 1) html += `<button class="page-btn" data-page="${page - 1}">← Previous</button>`;

    for (let i = 1; i <= totalPages; i++) {
      if (i === page) {
        html += `<span class="page-current">${i}</span>`;
      } else if (i === 1 || i === totalPages || Math.abs(i - page) <= 2) {
        html += `<button class="page-btn" data-page="${i}">${i}</button>`;
      } else if (i === page - 3 || i === page + 3) {
        html += "<span>...</span>";
      }
    }

    if (page < totalPages) html += `<button class="page-btn" data-page="${page + 1}">Next →</button>`;

    html += "</div>";
    $container.html(html);
  }

  /* ------------------ Auto Refresh (manual-only, safe) ------------------ */

  function startAnalyticsAutoRefresh() {
    // clear existing
    if (analyticsRefreshInterval) {
      clearInterval(analyticsRefreshInterval);
      analyticsRefreshInterval = null;
    }
    // As requested: disabled; only manual triggers
    log("Auto-refresh disabled: refresh on load, tab switch, timeframe change only.");
  }

  /* ------------------ Errors ------------------ */

  function showError(message) {
    console.error("[Analytics]", message);
    if (typeof w.showToast === "function") {
      w.showToast("Analytics Error", String(message || "Unknown error"), "error");
    }
  }

  /* ------------------ Public API ------------------ */
  w.agentHubAnalytics = {
    loadAnalyticsData,
    renderMarketOverviewChart,
    startAnalyticsAutoRefresh,
  };

  log("Module loaded");
})(window, document, jQuery);
