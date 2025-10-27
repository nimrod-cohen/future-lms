console.log('Future LMS main.js loaded');

class FutureLMS {
  //listen to javascript event FutureLMS/do_charge
  constructor() {
    document.addEventListener('FutureLMS:do_charge', e => {
      console.log('FutureLMS/do_charge event received', e.detail);
      this.doCharge(e.detail);
    });
  }
}

JSUtils.domReady(() => {
  window.futureLMS = new FutureLMS();
});
