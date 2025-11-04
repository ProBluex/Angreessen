/* admin.js (hardened & trimmed) */
(function (w, d, $) {
  "use strict";

  // Debug mode detection
  const DEBUG_MODE = w.location.hostname === 'localhost' || 
                     w.location.hostname.includes('127.0.0.1') ||
                     w.location.search.includes('debug=true');
  const debugLog = DEBUG_MODE ? console.log.bind(console) : () => {};
  const debugWarn = DEBUG_MODE ? console.warn.bind(console) : () => {};

  if (!w.agentHubData || !w.agentHubData.ajaxUrl || !w.agentHubData.nonce) {
    console.error("[agent-hub] Missing agentHubData config. Aborting.");
    return;
  }

  /* ---------- Utils ---------- */

  const $DOM = {
    toast: $("#agent-hub-toast"),
    settingsForm: $("#agent-hub-settings-form"),
    contentTBody: $("#content-table-body"),
    contentPagination: $("#content-pagination"),
    bulkGenerateBtn: $("#bulk-generate-links"),
    refreshContentBtn: $("#refresh-content"),
    timeframeSel: $("#analytics-timeframe"),
    agentBreakdown: $("#agent-breakdown"),
    topContent: $("#top-content"),
  };

  const HTML = {
    esc: (s) =>
      String(s ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;"),
    money: (v) => (Number.isFinite(+v) ? (+v).toFixed(2) : "0.00"),
  };

  const ajaxPost = (action, payload = {}) =>
    $.ajax({
      url: w.agentHubData.ajaxUrl,
      type: "POST",
      dataType: "json",
      timeout: 15000,
      data: { action, nonce: w.agentHubData.nonce, ...payload },
    });

  /* ---------- Toast (global for other scripts) ---------- */

  w.showToast = function (title, message, type = "success") {
    const id = `toast-${Date.now()}`;
    const icon = type === "success" ? "yes-alt" : "warning";
    const $toast = $(`
      <div class="toast-message ${HTML.esc(type)}" id="${id}">
        <div class="toast-icon"><span class="dashicons dashicons-${icon}"></span></div>
        <div class="toast-content">
          <div class="toast-title"></div>
          <div class="toast-text"></div>
        </div>
      </div>
    `);
    $toast.find(".toast-title").text(String(title ?? ""));
    $toast.find(".toast-text").text(String(message ?? ""));
    $DOM.toast.append($toast);
    setTimeout(
      () =>
        $toast.fadeOut(300, function () {
          $(this).remove();
        }),
      5000,
    );
  };

  /* ---------- Namespace ---------- */

  const hub = (w.agentHub = w.agentHub || {});
  debugLog('[admin.js] Hub namespace initialized');

  /* ---------- Tabs (hash-aware) ---------- */

  function activateTab(tab) {
    debugLog('[admin.js] Activating tab:', tab);
    
    $(".tab-button").removeClass("active");
    $(".tab-content").removeClass("active");
    $(`.tab-button[data-tab="${tab}"]`).addClass("active");
    $(`#tab-${tab}`).addClass("active");

    // Analytics tab is handled by analytics.js click handler
    // to prevent double-loading
    if (tab === "content") {
      if (typeof hub.loadContent === "function") {
        hub.loadContent(1);
      } else {
        console.error('[admin.js] hub.loadContent is not defined yet');
      }
      // Check sync status when switching to content tab
      if (typeof checkSyncStatus === "function") {
        checkSyncStatus();
      }
    }
    
    debugLog('[admin.js] Tab activated:', tab);
  }

  $(".tab-button").on("click", function () {
    const tab = $(this).data("tab");
    // avoid scroll jump: replaceState not hash set
    history.replaceState(null, "", `#${tab}`);
    activateTab(tab);
  });

  // Move initialization to jQuery ready to ensure all functions are defined
  $(function() {
    debugLog('[admin.js] Initializing tabs...');
    
    // Log available functions
    debugLog('[admin.js] All hub functions defined:', {
      loadContent: typeof hub.loadContent,
      loadAnalytics: typeof hub.loadAnalytics,
      toggleHumanAccess: typeof hub.toggleHumanAccess,
      generateLink: typeof hub.generateLink
    });
    
    // initialize from hash or default first tab
    const initialHash = (w.location.hash || "").slice(1);
    if (initialHash && $(`.tab-button[data-tab="${initialHash}"]`).length) {
      activateTab(initialHash);
    } else {
      const firstTab = $(".tab-button").first().data("tab");
      if (firstTab) activateTab(firstTab);
    }
    
    debugLog('[admin.js] Tabs initialized');
  });

  // react to external hash changes (e.g., browser back)
  $(w).on("hashchange", () => {
    const h = (w.location.hash || "").slice(1);
    if (h && $(`.tab-button[data-tab="${h}"]`).length) activateTab(h);
  });

  /* ---------- Settings Save ---------- */

  $DOM.settingsForm.on("submit", function (e) {
    e.preventDefault();
    const $btn = $(this).find('button[type="submit"]');
    const prev = $btn.html();
    $btn.prop("disabled", true).html('<span class="spinner is-active" style="float:none;"></span> Saving...');

    ajaxPost("agent_hub_save_settings", {
      api_key: $("#api_key").val(),
      payment_wallet: $("#payment_wallet").val(),
      default_price: $("#default_price").val(),
      network: $("#network").val(),
      auto_generate: $("#auto_generate").is(":checked") ? "true" : "false",
    })
      .done((res) => {
        res?.success
          ? w.showToast("Settings Saved", "Your settings have been saved successfully.", "success")
          : w.showToast("Error", res?.data?.message || "Failed to save settings.", "error");
      })
      .fail(() => w.showToast("Error", "Network error. Please try again.", "error"))
      .always(() => $btn.prop("disabled", false).html(prev));
  });

  /* ---------- Register/Sync Site ---------- */

  $("#register-site-button, #sync-site-button").on("click", function () {
    const $btn = $(this);
    const prev = $btn.html();
    $btn.prop("disabled", true).html('<span class="spinner is-active" style="float:none;"></span> Registering...');

    ajaxPost("agent_hub_register_site")
      .done((res) => {
        if (res?.success) {
          w.showToast("Success", "Site registered successfully!", "success");
          setTimeout(() => w.location.reload(), 1200);
        } else {
          w.showToast("Error", res?.data?.error || "Failed to register site.", "error");
        }
      })
      .fail(() => w.showToast("Error", "Network error. Please try again.", "error"))
      .always(() => $btn.prop("disabled", false).html(prev));
  });
  
  /* ---------- Sync Protection Status ---------- */
  
  // Check sync status when My Content tab is activated
  function checkSyncStatus() {
    ajaxPost("agent_hub_check_sync_status")
      .done((res) => {
        if (res?.success && res?.data?.needs_sync) {
          $("#sync-mismatch-banner").show();
          $("#sync-mismatch-message").text(res.data.message);
        } else {
          $("#sync-mismatch-banner").hide();
        }
      })
      .fail(() => {
        // Silently fail - don't show banner if check fails
        $("#sync-mismatch-banner").hide();
      });
  }
  
  // Handle sync button click in My Content tab
  $(document).on("click", "#sync-protection-status-content", function () {
    const $btn = $(this);
    const $banner = $("#sync-mismatch-banner");
    const prev = $btn.html();
    
    $btn.prop("disabled", true).html('<span class="spinner is-active" style="float:none;"></span> Syncing...');

    ajaxPost("agent_hub_sync_protection_status")
      .done((res) => {
        if (res?.success) {
          w.showToast("Success", res?.data?.message || "Protection status synced!", "success");
          $banner.hide();
          
          // Refresh content and overview stats
          if (typeof hub.loadContent === "function") hub.loadContent();
          if (w.agentHubOverview?.refreshStats) w.agentHubOverview.refreshStats();
        } else {
          w.showToast("Error", res?.data?.message || "Failed to sync protection status.", "error");
        }
      })
      .fail(() => w.showToast("Error", "Network error. Please try again.", "error"))
      .always(() => $btn.prop("disabled", false).html(prev));
  });
  
  // Add CSS for spinning icon
  $('<style>')
    .text('.dashicons.spin { animation: spin 1s linear infinite; } @keyframes spin { 100% { transform: rotate(360deg); } }')
    .appendTo('head');

  /* ---------- Content (list + pagination + toggles) ---------- */

  hub.loadContent = function (page = 1) {
    $DOM.contentTBody.html(
      '<tr><td colspan="5" style="text-align:center;"><span class="spinner is-active" style="float:none;margin:20px auto;"></span></td></tr>',
    );

    ajaxPost("agent_hub_get_content", { page, per_page: 10 })
      .done((res) => {
        const content = res?.success && Array.isArray(res?.data?.content) ? res.data.content : [];
        renderContentTable(content);
        renderPagination(res?.data?.pagination);
      })
      .fail(() =>
        $DOM.contentTBody.html('<tr><td colspan="5" style="text-align:center;">Error loading content.</td></tr>'),
      );
  };

  function renderContentTable(content) {
    if (!content?.length) {
      $DOM.contentTBody.html('<tr><td colspan="5" style="text-align:center;">No content found.</td></tr>');
      return;
    }

    // Build rows safely
    const rows = content
      .map((item) => {
        const id = +item.id;
        const title = HTML.esc(item.title);
        const type = HTML.esc(item.type);
        const price = HTML.money(item.price);
        const hasLink = !!item.has_link;
        const linkUrl = hasLink ? String(item.link_url || "") : "";
        const safeHref = hasLink ? HTML.esc(linkUrl) : "";
        const blockHumans = !!item.block_humans;

        const statusCell = hasLink
          ? `<span class="link-status active"><span class="dashicons dashicons-yes-alt"></span> Active</span>
           <a class="button-link" href="${safeHref}" target="_blank" rel="noopener">View Link</a>`
          : '<span class="link-status inactive"><span class="dashicons dashicons-warning"></span> Not Protected</span>';

        const humanCell = hasLink
          ? `<label class="human-toggle-wrapper">
             <input type="checkbox" class="human-toggle-checkbox" data-post-id="${id}" ${blockHumans ? "checked" : ""}>
             <span class="toggle-label">${blockHumans ? "Yes" : "No"}</span>
           </label>
           ${blockHumans ? `<a class="button-link" href="${safeHref}" target="_blank" rel="noopener">View Link</a>` : ""}`
          : '<span class="text-muted">N/A</span>';

        return `
        <tr>
          <td><strong>${title}</strong></td>
          <td>${type}</td>
          <td>$${price}</td>
          <td>${statusCell}</td>
          <td>${humanCell}</td>
        </tr>`;
      })
      .join("");

    $DOM.contentTBody.html(rows);
  }

  function renderPagination(pagination) {
    const p = pagination || {};
    if (!p.total_pages || p.total_pages <= 1) {
      $DOM.contentPagination.empty();
      return;
    }

    const prevDisabled = p.current_page <= 1;
    const nextDisabled = p.current_page >= p.total_pages;

    const html = `
      <div class="tablenav"><div class="tablenav-pages">
        <span class="displaying-num">${HTML.esc(p.total_posts || 0)} items</span>
        <span class="pagination-links">
          ${
            prevDisabled
              ? '<span class="tablenav-pages-navspan button disabled">«</span> <span class="tablenav-pages-navspan button disabled">‹</span>'
              : `<a class="first-page button" data-page="1" style="cursor:pointer;">«</a>
               <a class="prev-page button" data-page="${p.current_page - 1}" style="cursor:pointer;">‹</a>`
          }
          <span class="paging-input">Page ${p.current_page} of ${p.total_pages}</span>
          ${
            nextDisabled
              ? '<span class="tablenav-pages-navspan button disabled">›</span> <span class="tablenav-pages-navspan button disabled">»</span>'
              : `<a class="next-page button" data-page="${p.current_page + 1}" style="cursor:pointer;">›</a>
               <a class="last-page button" data-page="${p.total_pages}" style="cursor:pointer;">»</a>`
          }
        </span>
      </div></div>`;
    $DOM.contentPagination.html(html);
  }

  // Delegated events (prevents double-binding on redraws)
  $DOM.contentPagination.on("click", "a[data-page]", function () {
    hub.loadContent(parseInt($(this).data("page"), 10));
  });

  $DOM.contentTBody.on("change", ".human-toggle-checkbox", function () {
    const postId = parseInt($(this).data("post-id"), 10);
    const checked = $(this).is(":checked");
    hub.toggleHumanAccess(postId, checked);
  });

  /* ---------- Toggle Human Access (kept public) ---------- */
  hub.toggleHumanAccess = function (postId, blockHumans) {
    ajaxPost("agent_hub_toggle_human_access", {
      post_id: postId,
      block_humans: blockHumans ? "true" : "false",
    })
      .done((res) => {
        if (res?.success) {
          w.showToast("Success", `Humans ${blockHumans ? "blocked" : "allowed"}`, "success");
        } else {
          w.showToast("Error", res?.data?.message || "Failed to update settings.", "error");
        }
      })
      .fail(() => w.showToast("Error", "Network error. Please try again.", "error"))
      .always(() => hub.loadContent()); // refresh or revert UI
  };

  /* ---------- Generate Link (kept public) ---------- */
  hub.generateLink = function (postId) {
    if (!w.confirm("Generate 402link for this page?")) return;
    ajaxPost("agent_hub_generate_link", { post_id: postId })
      .done((res) => {
        if (res?.success) {
          w.showToast("Success", "402link generated successfully!", "success");
          hub.loadContent();
          if (w.agentHubAnalytics?.loadAnalyticsData) w.agentHubAnalytics.loadAnalyticsData();
        } else {
          w.showToast("Error", res?.data?.error || "Failed to generate link.", "error");
        }
      })
      .fail(() => w.showToast("Error", "Network error. Please try again.", "error"));
  };

  /* ---------- Bulk Generate ---------- */
  // NOTE: Bulk generate button is now handled by batch-processor.js
  // which provides a modal interface with real-time progress updates.
  // The old simple handler below is commented out:
  
  /*
  $DOM.bulkGenerateBtn.on("click", function () {
    const $btn = $(this);
    const prev = $btn.html();
    $btn.prop("disabled", true).html('<span class="spinner is-active" style="float:none;"></span> Generating...');

    ajaxPost("agent_hub_bulk_generate")
      .done((res) => {
        res?.success
          ? w.showToast("Success", res?.data?.message || "Links generated.", "success")
          : w.showToast("Error", res?.data?.message || "Failed to generate links.", "error");
        hub.loadContent();
      })
      .fail((_, __, err) => w.showToast("Error", `Network error: ${err || "Unknown"}`, "error"))
      .always(() => $btn.prop("disabled", false).html(prev));
  });
  */

  $DOM.refreshContentBtn.on("click", () => hub.loadContent());

  /* ---------- Analytics ---------- */
  hub.loadAnalytics = function () {
    const timeframe = $DOM.timeframeSel.val();
    ajaxPost("agent_hub_get_analytics", { timeframe }).done((res) => {
      if (res?.success && res.data) renderAnalytics(res.data);
    });
  };

  function renderAnalytics(data) {
    const agents = Array.isArray(data.agent_breakdown) ? data.agent_breakdown : [];
    const tops = Array.isArray(data.top_content) ? data.top_content : [];

    const agentHtml = agents
      .map(
        (a) => `
      <div style="display:flex;justify-content:space-between;padding:10px;border-bottom:1px solid #ddd;">
        <span><strong>${HTML.esc(a.name)}</strong></span>
        <span>${HTML.esc(a.crawls)} crawls | $${HTML.money(a.revenue)}</span>
      </div>`,
      )
      .join("");
    $DOM.agentBreakdown.html(agentHtml || "No agent data available.");

    const topHtml = tops
      .map(
        (t, i) => `
      <div style="display:flex;justify-content:space-between;padding:10px;border-bottom:1px solid #ddd;">
        <span><strong>${i + 1}. ${HTML.esc(t.title)}</strong></span>
        <span>${HTML.esc(t.crawls)} crawls | $${HTML.money(t.revenue)}</span>
      </div>`,
      )
      .join("");
    $DOM.topContent.html(topHtml || "No content data available.");
  }

  $DOM.timeframeSel.on("change", () => hub.loadAnalytics());

  /* ---------- Auto-Refresh (visibility-aware) ---------- */

  (function () {
    let timer = null;
    const T = 30000;

    const tick = () => {
      const tab = $(".tab-button.active").data("tab");
      if (!tab) return;
      if (tab === "content") hub.loadContent();
      if (tab === "analytics") hub.loadAnalytics();
      // overview handled elsewhere
      // console.debug('[402links] Auto-refreshed', tab, 'at', new Date().toLocaleTimeString());
    };

    const start = () => {
      if (!timer) timer = setInterval(tick, T);
    };
    const stop = () => {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    };

    const visibilityHandler = () => (d.hidden ? stop() : start());
    d.addEventListener("visibilitychange", visibilityHandler);
    $(w).on("beforeunload", stop);

    start();
  })();
})(window, document, jQuery);
