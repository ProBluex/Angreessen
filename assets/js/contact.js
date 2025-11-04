/* contact.js (hardened, lean, collision-safe) */
(function (w, d, $) {
  "use strict";

  // Soft guard; keep working even if agentHubData is missing
  const cfg = w.agentHubData || {};
  const ENDPOINT = cfg.contactEndpoint || "https://api.402links.com/functions/v1/submit-contact-message";

  // state
  let submitCount = 0;
  const MAX_SUBMISSIONS = 3;
  let inflight = null; // jqXHR for aborting
  let typingTimer = null;

  const $form = $("#contact-form");
  const $name = $("#contact-name");
  const $email = $("#contact-email");
  const $subject = $("#contact-subject");
  const $message = $("#contact-message");
  const $success = $("#contact-success");
  const $sendAnother = $("#send-another");
  const $submit = $("#contact-submit");

  const $nameErr = $("#name-error");
  const $emailErr = $("#email-error");
  const $messageErr = $("#message-error");
  const $fieldErr = $(".field-error");
  const $count = $("#message-count");

  // optional honeypot (hidden field)
  const $honeypot = $("#contact-website"); // if present in DOM, bots will fill it

  // small helpers
  const setBusy = (busy) => {
    if (busy) {
      $submit.prop("disabled", true).html('<span class="spinner is-active" style="float:none;"></span> Sending...');
    } else {
      $submit.prop("disabled", false).text($submit.data("label") || "Send");
    }
  };

  const showToast = (title, msg, type) =>
    typeof w.showToast === "function" ? w.showToast(title, msg, type) : w.alert(`${title}: ${msg}`);

  // character counter + soft limit
  $message.on("input", function () {
    // debounce repaint to avoid layout thrash on long pastes
    if (typingTimer) clearTimeout(typingTimer);
    typingTimer = setTimeout(() => {
      const text = String($message.val() || "");
      const len = text.length;
      $count.text(len);

      if (len > 2000) {
        $messageErr.text("Message is too long").show();
      } else {
        $messageErr.hide();
      }
    }, 60);
  });

  function validateEmail(s) {
    // pragmatic, anchored RFC5322-lite (avoids catastrophic backtracking)
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(s);
  }

  function trimTo(s, max) {
    const str = String(s || "").trim();
    return str.length <= max ? str : str.slice(0, max);
  }

  function validateForm() {
    let ok = true;
    $fieldErr.hide();

    const name = trimTo($name.val(), 100);
    if (!name) {
      $nameErr.text("Name is required").show();
      ok = false;
    } else if (name.length > 100) {
      $nameErr.text("Name must be less than 100 characters").show();
      ok = false;
    }

    const email = trimTo($email.val(), 255);
    if (!email || !validateEmail(email)) {
      $emailErr.text("Valid email address is required").show();
      ok = false;
    }

    const msg = trimTo($message.val(), 2000);
    if (!msg) {
      $messageErr.text("Message cannot be empty").show();
      ok = false;
    } else if (msg.length > 2000) {
      $messageErr.text("Message must be less than 2000 characters").show();
      ok = false;
    }

    // optional subject guard (do not block if absent)
    const subj = trimTo($subject.val(), 200);
    if ($subject.length && subj.length > 200) {
      $("#subject-error").text("Subject must be less than 200 characters").show();
      ok = false;
    }

    // honeypot check: if present & non-empty, treat as spam but respond politely
    if ($honeypot.length && String($honeypot.val() || "").trim() !== "") {
      ok = false;
      showToast("Error", "Failed to send message. Please try again.", "error");
    }
    return ok;
  }

  // preserve original button label
  if (!$submit.data("label")) $submit.data("label", $submit.text());

  $form.on("submit", function (e) {
    e.preventDefault();

    if (submitCount >= MAX_SUBMISSIONS) {
      showToast("Limit reached", "You’ve reached the submission limit. Please try again later.", "error");
      return;
    }

    if (!validateForm()) return;

    // prevent rapid double-submits: abort previous if still in flight
    if (inflight && inflight.abort) inflight.abort();

    const payload = {
      name: trimTo($name.val(), 100),
      email: trimTo($email.val(), 255),
      subject: trimTo($subject.val(), 200) || undefined,
      message: trimTo($message.val(), 2000),
      site_id: cfg.siteId || undefined,
    };

    setBusy(true);

    inflight = $.ajax({
      url: ENDPOINT,
      type: "POST",
      contentType: "application/json",
      dataType: "json",
      timeout: 20000,
      data: JSON.stringify(payload),
    })
      .done((res) => {
        if (res && res.success) {
          submitCount++;
          $form.hide();
          $success.fadeIn();

          // reset form for potential next message
          $form[0].reset();
          $count.text("0");
          $fieldErr.hide();
        } else {
          const msg = res?.message || "Please try again.";
          showToast("Failed to send message", msg, "error");
        }
      })
      .fail((xhr, _status, err) => {
        let msg = "Failed to send message. Please try again.";
        if (xhr?.status === 429) msg = "Rate limit exceeded. Please wait before sending another message.";
        else if (xhr?.responseJSON?.message) msg = xhr.responseJSON.message;
        else if (err) msg = String(err);
        showToast("Contact Error", msg, "error");
      })
      .always(() => {
        setBusy(false);
        inflight = null;
      });
  });

  // “Send another” path preserves session rate-limit
  $sendAnother.on("click", function () {
    if (submitCount >= MAX_SUBMISSIONS) {
      showToast("Limit reached", "You’ve reached the submission limit for this session.", "error");
      return;
    }
    $success.hide();
    $form.fadeIn();
  });

  // Defensive: clear inflight on unload to avoid hanging connections
  $(w).on("beforeunload", function () {
    if (inflight && inflight.abort) inflight.abort();
  });

  // ============= RECOVERY FUNCTIONALITY =============
  // One-click API key recovery for reinstalled WordPress sites
  const $recoverySidebar = $("#recovery-sidebar");
  const $reconnectBtn = $("#reconnect-site-btn");
  const $recoveryStatus = $("#recovery-status");
  const $recoverySiteId = $("#recovery-site-id");
  const $recoverySiteUrl = $("#recovery-site-url");

  const siteId = cfg.siteId || "";
  const siteUrl = cfg.siteUrl || "";

  // Check if this is a recovery scenario (site_id exists but api_key missing)
  const isRecoveryScenario = siteId && !$("body").hasClass("api-key-exists");

  if (isRecoveryScenario && $recoverySidebar.length) {
    $recoverySidebar.show();
    $recoverySiteId.text(siteId);
    $recoverySiteUrl.text(siteUrl);
  }

  // Recovery button click handler
  $reconnectBtn.on("click", function (e) {
    e.preventDefault();

    const $btn = $(this);
    const originalHtml = $btn.html();

    // Disable button and show loading
    $btn.prop("disabled", true).html('<span class="spinner is-active"></span> Reconnecting...');
    $recoveryStatus.hide().html("");

    // Call edge function for recovery
    $.ajax({
      url: "https://cnionwnknwnzpwfuacse.supabase.co/functions/v1/recover-wordpress-api-key",
      type: "POST",
      contentType: "application/json",
      dataType: "json",
      timeout: 15000,
      data: JSON.stringify({
        site_id: siteId,
        site_url: siteUrl,
      }),
    })
      .done(function (response) {
        if (response && response.success) {
          // Save recovered API key via WordPress AJAX
          $.post(
            cfg.ajaxUrl || "/wp-admin/admin-ajax.php",
            {
              action: "agent_hub_save_recovered_key",
              nonce: cfg.nonce,
              api_key: response.api_key,
            },
            function (saveResult) {
              if (saveResult.success) {
                // Build success message with sync details
                const data = saveResult.data || {};
                let statusHtml = '<div class="notice notice-success">' +
                  "<p><strong>✅ " + escapeHtml(data.message || "Site reconnected successfully!") + "</strong></p>";
                
                // Show sync details if available
                if (data.sync_completed) {
                  const details = [];
                  if (data.added > 0) details.push(data.added + " restored");
                  if (data.updated > 0) details.push(data.updated + " updated");
                  if (data.already_synced > 0) details.push(data.already_synced + " already synced");
                  if (details.length > 0) {
                    statusHtml += "<p>📄 Protection status: " + escapeHtml(details.join(", ")) + "</p>";
                  }
                }
                
                statusHtml += "<p>Reloading dashboard...</p></div>";
                
                $recoveryStatus.html(statusHtml).show();

                // Reload page after 2 seconds to show updated dashboard
                setTimeout(function () {
                  window.location.reload();
                }, 2000);
              } else {
                showRecoveryError(saveResult.data?.message || "Failed to save API key. Please try again.");
              }
            }
          ).fail(function () {
            showRecoveryError("Failed to save API key. Please try again.");
          });
        } else {
          showRecoveryError(response?.message || "Recovery failed. Please contact support.");
        }
      })
      .fail(function (xhr) {
        let message = "Network error during recovery. Please try again.";
        if (xhr.status === 429) {
          message = "Too many recovery attempts. Please wait an hour or contact support.";
        } else if (xhr.status === 404) {
          message = "Site not found in our system. Please contact support.";
        } else if (xhr.status === 403) {
          message = "Site URL verification failed. Please contact support.";
        } else if (xhr.responseJSON?.message) {
          message = xhr.responseJSON.message;
        }
        showRecoveryError(message);
      })
      .always(function () {
        $btn.prop("disabled", false).html(originalHtml);
      });
  });

  function showRecoveryError(message) {
    $recoveryStatus
      .html(
        '<div class="notice notice-error">' +
          "<p><strong>⚠️ Recovery Failed</strong></p>" +
          "<p>" +
          escapeHtml(message) +
          "</p>" +
          "<p><small>If this persists, please contact support with your Site ID.</small></p>" +
          "</div>"
      )
      .show();
  }

  function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }
})(window, document, jQuery);
