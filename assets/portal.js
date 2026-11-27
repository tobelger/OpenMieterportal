'use strict';
(() => {
  function consumeInvitation() {
    const params = new URLSearchParams(window.location.hash.slice(1));
    const invitation = params.get('einladung');
    if (invitation && /^(?:[A-HJ-NP-Z2-9]{16}|[a-f0-9]{64})$/i.test(invitation)) {
      const field = document.getElementById('bpm-code');
      // Never send an invitation to a third-party QR service or put it in a query string.
      history.replaceState(null, '', window.location.pathname + window.location.search);
      if (field) {
        field.value = invitation;
        document.getElementById('bpm-activation')?.scrollIntoView({ block: 'start' });
      }
    }
  }
  consumeInvitation();
  window.addEventListener('hashchange', consumeInvitation);
  for (const element of document.querySelectorAll('[data-bpm-qr]')) {
    if (typeof qrcode !== 'function') continue;
    const qr = qrcode(0, 'M');
    qr.addData(element.dataset.bpmQr);
    qr.make();
    element.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 16, scalable: true });
  }
  document.getElementById('bpm-print')?.addEventListener('click', () => window.print());
})();

// Sort/filter only documents already authorized and rendered for this household.
document.addEventListener('DOMContentLoaded',()=>{
 const table=document.getElementById('bpm-document-table');if(!table)return;
 const body=table.tBodies[0],type=document.getElementById('bpm-document-type'),search=document.getElementById('bpm-document-search'),result=document.getElementById('bpm-document-result');
 const filter=()=>{let visible=0;for(const row of body.rows){const match=(!type.value||row.dataset.category===type.value)&&row.textContent.toLocaleLowerCase(window.OpenMieterportalI18n?.locale||document.documentElement.lang||'de-DE').includes(search.value.toLocaleLowerCase(window.OpenMieterportalI18n?.locale||document.documentElement.lang||'de-DE').trim());row.hidden=!match;if(match)visible++;}result.textContent=(window.OpenMieterportalI18n?.documentCount||'%1$d von %2$d Dokumenten').replace('%1$d',String(visible)).replace('%2$d',String(body.rows.length));};
 type.addEventListener('change',filter);search.addEventListener('input',filter);
 table.querySelectorAll('[data-bpm-sort]').forEach(button=>button.addEventListener('click',()=>{const col=Number(button.dataset.bpmSort),head=button.closest('th'),ascending=head.getAttribute('aria-sort')!=='ascending';table.querySelectorAll('th[aria-sort]').forEach(th=>th.setAttribute('aria-sort','none'));head.setAttribute('aria-sort',ascending?'ascending':'descending');const rows=Array.from(body.rows);rows.sort((a,b)=>{const va=a.cells[col].dataset.sortValue||a.cells[col].textContent,vb=b.cells[col].dataset.sortValue||b.cells[col].textContent;return va.localeCompare(vb,window.OpenMieterportalI18n?.locale||document.documentElement.lang||'de',{numeric:true})*(ascending?1:-1);});body.append(...rows);filter();}));filter();
});

// Enhance the existing section links to keyboard-accessible portal tabs.
(() => {
  const init = () => {
    const nav = document.querySelector('.bpm-portal .bpm-nav');
    if (!nav) return;
    const mainTabs = Array.from(nav.querySelectorAll('a[href^="#bpm-"]'));
    const tabs = [...mainTabs, ...document.querySelectorAll('.bpm-user-menu a[href^="#bpm-"]')];
    const pairs = tabs.map(tab => ({ tab, panel: document.getElementById(tab.hash.slice(1)) })).filter(pair => pair.panel);
    if (pairs.length !== tabs.length) return;
    const pinned = document.querySelector('.bpm-communication-prompt');
    nav.setAttribute('role', 'tablist');
    pairs.forEach(({tab, panel}) => {
      tab.id = `${panel.id}-tab`;
      if (nav.contains(tab)) tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-controls', panel.id);
      if (panel !== pinned) {
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', tab.id);
        panel.tabIndex = 0;
      }
    });
    const select = (hash, focus = false) => {
      const position = { left: window.scrollX, top: window.scrollY, behavior: 'instant' };
      const active = pairs.find(pair => pair.tab.hash === hash) || pairs[0];
      pairs.forEach(({tab, panel}) => {
        const selected = tab === active.tab;
        if (nav.contains(tab)) {
          tab.setAttribute('aria-selected', String(selected));
          tab.tabIndex = selected || (!nav.contains(active.tab) && tab === mainTabs[0]) ? 0 : -1;
        } else {
          if (selected) tab.setAttribute('aria-current', 'page'); else tab.removeAttribute('aria-current');
        }
        if (panel !== pinned) panel.hidden = !selected;
      });
      if (active.panel.id === 'bpm-messages' && typeof window.wpsc_reset_responsive_style === 'function') {
        // SupportCandy initialized inside a hidden tab measures zero width.
        requestAnimationFrame(() => { if (!active.panel.hidden) window.wpsc_reset_responsive_style(true); });
      }
      if (focus) active.tab.focus({ preventScroll: true });
      window.scrollTo(position);
      requestAnimationFrame(() => window.scrollTo(position));
    };
    const restore = () => {
      // SupportCandy adds its query string even while another tab is visible.
      // On Back/Forward, only the saved tab anchor identifies the visited tab.
      select(window.location.hash);
    };
    const navigate = (hash, focus = false) => {
      const current = pairs.find(pair => pair.tab.getAttribute('aria-selected') === 'true' || pair.tab.getAttribute('aria-current') === 'page');
      if (current && current.tab.hash !== hash) {
        // Record the visible tab before creating the next history entry, even
        // when SupportCandy has rewritten the URL in the background.
        if (window.location.hash !== current.tab.hash) history.replaceState(history.state, '', current.tab.hash);
        history.pushState(null, '', hash);
      }
      select(hash, focus);
    };
    // Intercept before Divi's anchor handlers can start a scroll animation.
    nav.addEventListener('click', event => {
      const tab = event.target.closest('[role=tab]');
      if (!tab || !nav.contains(tab)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      navigate(tab.hash);
    }, true);
    pairs.filter(pair => nav.contains(pair.tab)).forEach(({tab}, index, mainPairs) => {
      tab.addEventListener('keydown', event => {
        let next;
        if (event.key === 'ArrowRight') next = (index + 1) % mainPairs.length;
        if (event.key === 'ArrowLeft') next = (index + mainPairs.length - 1) % mainPairs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = mainPairs.length - 1;
        if (next === undefined) return;
        event.preventDefault();
        const target = mainPairs[next].tab;
        navigate(target.hash, true);
      });
    });
    // Links inside panels (for example profile changes) also open their target tab.
    document.querySelector('.bpm-portal').addEventListener('click', event => {
      const link = event.target.closest('a[href^="#bpm-"]');
      if (link && !nav.contains(link) && pairs.some(pair => pair.tab.hash === link.hash)) {
        event.preventDefault();
        event.stopPropagation();
        navigate(link.hash);
        const menu = link.closest('.bpm-user-menu');
        if (menu) menu.open = false;
      }
    }, true);
    window.addEventListener('hashchange', restore);
    window.addEventListener('popstate', restore);
    let emailToken = '';
    try { emailToken = sessionStorage.getItem('bpmEmailConfirmation') || ''; } catch {}
    const confirmation = document.getElementById('bpm-email-confirmation');
    if (/^[a-f0-9]{64}$/.test(emailToken) && confirmation) {
      confirmation.hidden = false;
      confirmation.elements.email_token.value = emailToken;
      confirmation.addEventListener('submit', () => { try { sessionStorage.removeItem('bpmEmailConfirmation'); } catch {} });
      document.getElementById('bpm-email-dismiss')?.addEventListener('click', () => {
        try { sessionStorage.removeItem('bpmEmailConfirmation'); } catch {}
        confirmation.hidden = true;
      });
    }
    const userMenu = document.querySelector('.bpm-user-menu');
    document.addEventListener('click', event => { if (userMenu && !userMenu.contains(event.target)) userMenu.open = false; });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && userMenu?.open) { userMenu.open = false; userMenu.querySelector('summary').focus({preventScroll:true}); } });
    const initialHash = emailToken && confirmation ? '#bpm-account-profile' : window.location.hash;
    const ticketLink = new URLSearchParams(window.location.search).has('wpsc-section');
    const firstHash = pairs.some(pair => pair.tab.hash === initialHash) ? initialHash : ticketLink ? '#bpm-messages' : pairs[0].tab.hash;
    history.replaceState(history.state, '', firstHash);
    select(firstHash);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
