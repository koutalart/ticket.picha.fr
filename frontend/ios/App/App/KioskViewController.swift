import Capacitor
import UIKit

class KioskViewController: CAPBridgeViewController {
    override open func capacitorDidLoad() {
        bridge?.registerPluginInstance(PichaPrinterPlugin())
    }
}
