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
})(window, document, jQuery);
