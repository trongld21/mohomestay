// Local development only: refresh when saved source files change.
(() => {
  let version;
  let polling = false;
  const check = async () => {
    if (document.hidden || polling) return;
    polling = true;
    try {
      const response = await fetch('/__dev/version', {cache: 'no-store'});
      if (!response.ok) return;
      const current = await response.text();
      if (version !== undefined && version !== current) location.reload();
      version = current;
    } catch {
      // The local server may be restarting; retry on the next interval.
    } finally {
      polling = false;
    }
  };
  check();
  setInterval(check, 1500);
  document.addEventListener('visibilitychange', check);
})();
