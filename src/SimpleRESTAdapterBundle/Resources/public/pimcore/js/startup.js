/**
 * Simple REST Adapter (Pimcore 11 compatibility bootstrap).
 */

pimcore.registerNS("pimcore.plugin.simpleRestAdapterBundle");

pimcore.plugin.simpleRestAdapterBundle = Class.create(pimcore.plugin.admin, {
  getClassName: function () {
    return "pimcore.plugin.simpleRestAdapterBundle";
  },

  initialize: function () {
    pimcore.plugin.broker.registerPlugin(this);

    // Ensure our DataHub adapter JS is loaded in admin
    this.loadDependencies();
  },

  loadDependencies: function () {
    const base = "/bundles/simplerestadapter/pimcore/js/";

    const files = [
      "config-item.js",
      "grid-config-dialog.js",
      "adapter.js",
    ];

    // Load sequentially to keep Class.create dependencies safe
    const loadNext = (idx) => {
      if (idx >= files.length) {
        return;
      }

      const url = base + files[idx];

      // already loaded?
      if (document.querySelector(`script[data-sra="${files[idx]}"]`)) {
        loadNext(idx + 1);
        return;
      }

      const s = document.createElement("script");
      s.type = "text/javascript";
      s.async = false;
      s.src = url;
      s.setAttribute("data-sra", files[idx]);

      s.onload = () => loadNext(idx + 1);
      s.onerror = () => {
        // eslint-disable-next-line no-console
        console.error("[SimpleRESTAdapterBundle] Failed to load", url);
        loadNext(idx + 1);
      };

      document.head.appendChild(s);
    };

    loadNext(0);
  },
});

new pimcore.plugin.simpleRestAdapterBundle();
const simpleRestAdapterBundle = new pimcore.plugin.simpleRestAdapterBundle();

(function registerSimpleRestAdapterWhenDataHubReady() {
  const tryRegister = () => {
    // Adapter class must exist
    const Adapter = pimcore?.plugin?.datahub?.adapter?.simpleRest;
    if (!Adapter) return false;

    // Variant A: registry object exists
    if (pimcore?.plugin?.datahub?.adapterRegistry?.register) {
      pimcore.plugin.datahub.adapterRegistry.register("simpleRest", Adapter);
      return true;
    }

    // Variant B: adapters map exists
    if (pimcore?.plugin?.datahub?.adapters && typeof pimcore.plugin.datahub.adapters === "object") {
      pimcore.plugin.datahub.adapters.simpleRest = Adapter;
      return true;
    }

    // Variant C: factory exists
    if (pimcore?.plugin?.datahub?.getAdapter && typeof pimcore.plugin.datahub.getAdapter === "function") {
      // nothing to do here, but indicates DataHub is loaded
      return false;
    }

    return false;
  };

  // Try immediately + retry a few times (DataHub scripts might load later)
  let tries = 0;
  const timer = setInterval(() => {
    tries += 1;
    if (tryRegister() || tries > 40) {
      clearInterval(timer);
      if (tries > 40) {
        // eslint-disable-next-line no-console
        console.warn("[SimpleRESTAdapterBundle] DataHub registry not found; adapter may not appear in UI.");
      }
    }
  }, 250);
})();
