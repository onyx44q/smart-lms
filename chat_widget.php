<?php
/**
 * chat_widget.php — floating SmartLMS assistant (used by the admin dashboard).
 * Talks to chatbot.php, which answers live from the database.
 * Element ids are prefixed "cw-" so they cannot clash with a host page.
 */
$cw_label = $cw_label ?? 'Admin AI · Live data';
$cw_chips = $cw_chips ?? ['System overview', 'Risk overview', 'Recommendation feedback', 'Fees overview', 'Pending leave'];
?>
<style>
#cw-fab{position:fixed;bottom:28px;right:28px;z-index:9999;width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#4f46e5);box-shadow:0 8px 25px rgba(99,102,241,.45);display:flex;align-items:center;justify-content:center;cursor:pointer;border:none;transition:transform .2s;}
#cw-fab:hover{transform:scale(1.1);}
#cw-window{position:fixed;bottom:100px;right:28px;z-index:9998;width:370px;max-width:calc(100vw - 32px);height:520px;max-height:calc(100vh - 130px);background:#fff;border-radius:24px;box-shadow:0 20px 60px rgba(0,0,0,.18);display:flex;flex-direction:column;transform:scale(.85) translateY(20px);opacity:0;pointer-events:none;transition:all .25s cubic-bezier(.34,1.56,.64,1);overflow:hidden;}
#cw-window.open{transform:scale(1) translateY(0);opacity:1;pointer-events:all;}
#cw-header{background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:16px 18px;display:flex;align-items:center;justify-content:space-between;color:#fff;}
#cw-messages{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth;}
.cw-bubble{max-width:88%;padding:10px 14px;border-radius:18px;font-size:12px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;}
.cw-bot{background:#f1f5f9;color:#1e293b;border-bottom-left-radius:4px;align-self:flex-start;}
.cw-user{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border-bottom-right-radius:4px;align-self:flex-end;}
.cw-typing{display:flex;gap:4px;padding:10px 14px;background:#f1f5f9;border-radius:18px;align-self:flex-start;}
.cw-typing span{width:7px;height:7px;background:#94a3b8;border-radius:50%;animation:cw-dot 1.2s infinite;}
.cw-typing span:nth-child(2){animation-delay:.2s}.cw-typing span:nth-child(3){animation-delay:.4s}
@keyframes cw-dot{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)}}
#cw-input-row{padding:12px 14px;border-top:1px solid #f1f5f9;display:flex;gap:8px;align-items:center;}
#cw-input{flex:1;border:1px solid #e2e8f0;border-radius:12px;padding:9px 13px;font-size:12px;outline:none;font-family:inherit;}
#cw-input:focus{border-color:#6366f1;}
#cw-send{width:36px;height:36px;border-radius:10px;background:#6366f1;border:none;cursor:pointer;color:#fff;}
.cw-chips{display:flex;flex-wrap:wrap;gap:6px;}
.cw-chip{font-size:10px;padding:4px 10px;border-radius:20px;background:#eff6ff;color:#3b82f6;cursor:pointer;border:1px solid #bfdbfe;font-weight:700;}
.cw-chip:hover{background:#dbeafe;}
</style>

<button id="cw-fab" type="button" onclick="cwToggle()" title="AI Assistant"><i class="fa-solid fa-comments" style="color:#fff;font-size:20px;"></i></button>

<div id="cw-window">
  <div id="cw-header">
    <div style="display:flex;align-items:center;gap:12px;">
      <div style="width:36px;height:36px;background:rgba(255,255,255,.2);border-radius:12px;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-brain"></i></div>
      <div><div style="font-weight:900;font-size:14px;">SmartLMS Assistant</div><div style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#c7d2fe;"><?php echo htmlspecialchars($cw_label); ?></div></div>
    </div>
    <button type="button" onclick="cwToggle()" style="background:none;border:none;color:rgba(255,255,255,.75);cursor:pointer;font-size:18px;"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div id="cw-messages">
    <div class="cw-bubble cw-bot">Hello! I answer live from the system — ask me for an overview, risk, fees, recommendation feedback or pending leave.</div>
  </div>
  <div id="cw-input-row">
    <input id="cw-input" type="text" placeholder="Ask me anything…" onkeydown="if(event.key==='Enter'){event.preventDefault();cwSend();}">
    <button id="cw-send" type="button" onclick="cwSend()"><i class="fa-solid fa-paper-plane" style="font-size:12px;"></i></button>
  </div>
</div>

<script>
(function () {
  const STARTER = <?php echo json_encode(array_values($cw_chips)); ?>;
  let open = false, shown = false;
  const $ = id => document.getElementById(id);
  function scroll() { const m = $('cw-messages'); m.scrollTop = m.scrollHeight; }
  function bubble(text, who) {
    const el = document.createElement('div');
    el.className = 'cw-bubble ' + (who === 'user' ? 'cw-user' : 'cw-bot');
    el.textContent = text;                       // textContent => no HTML injection
    $('cw-messages').appendChild(el); scroll();
  }
  function chips(list) {
    document.querySelectorAll('.cw-chips').forEach(e => e.remove());
    if (!list || !list.length) return;
    const w = document.createElement('div'); w.className = 'cw-chips';
    list.forEach(c => { const s = document.createElement('span'); s.className = 'cw-chip'; s.textContent = c; s.onclick = () => { $('cw-input').value = c; window.cwSend(); }; w.appendChild(s); });
    $('cw-messages').appendChild(w); scroll();
  }
  window.cwToggle = function () {
    open = !open; $('cw-window').classList.toggle('open', open);
    if (open) { $('cw-input').focus(); if (!shown) { shown = true; chips(STARTER); } }
  };
  window.cwSend = async function () {
    const input = $('cw-input'), msg = input.value.trim();
    if (!msg) return;
    input.value = ''; bubble(msg, 'user'); chips([]);
    const t = document.createElement('div'); t.className = 'cw-typing'; t.id = 'cw-typing'; t.innerHTML = '<span></span><span></span><span></span>';
    $('cw-messages').appendChild(t); scroll();
    try {
      const form = new FormData(); form.append('message', msg);
      const res = await fetch('chatbot.php', { method: 'POST', body: form });
      const data = await res.json();
      $('cw-typing')?.remove();
      bubble(data.reply || 'Sorry, I could not process that.', 'bot'); chips(data.chips || []);
    } catch (e) { $('cw-typing')?.remove(); bubble('Connection error. Please try again.', 'bot'); }
  };
})();
</script>
