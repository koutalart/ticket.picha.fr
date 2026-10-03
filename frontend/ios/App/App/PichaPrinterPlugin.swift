import Capacitor
import Foundation
import Network
import UIKit

/// Prints from the iPad itself (D19d): raw ZPL to the Zebra over the venue
/// Wi-Fi (TCP 9100), and PDF tickets through AirPrint.
@objc(PichaPrinterPlugin)
public class PichaPrinterPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "PichaPrinterPlugin"
    public let jsName = "PichaPrinter"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "sendRaw", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "printPdf", returnType: CAPPluginReturnPromise),
    ]

    private let queue = DispatchQueue(label: "fr.picha.kiosk.printer")

    @objc func sendRaw(_ call: CAPPluginCall) {
        guard let host = call.getString("host"), !host.isEmpty,
              let data = call.getString("data")?.data(using: .utf8),
              let port = NWEndpoint.Port(rawValue: UInt16(call.getInt("port") ?? 9100)) else {
            call.reject("Invalid printer request")
            return
        }
        let timeout = Double(call.getInt("timeoutMs") ?? 5000) / 1000

        let connection = NWConnection(host: NWEndpoint.Host(host), port: port, using: .tcp)
        var finished = false
        let finish: (String?) -> Void = { error in
            guard !finished else { return }
            finished = true
            connection.cancel()
            if let error = error {
                call.reject(error)
            } else {
                call.resolve()
            }
        }

        connection.stateUpdateHandler = { state in
            switch state {
            case .ready:
                connection.send(content: data, completion: .contentProcessed { error in
                    finish(error.map { "Printer write failed: \($0.localizedDescription)" })
                })
            case .failed(let error), .waiting(let error):
                finish("Printer unreachable (\(host)): \(error.localizedDescription)")
            default:
                break
            }
        }
        queue.asyncAfter(deadline: .now() + timeout) {
            finish("Printer unreachable (\(host)): timeout")
        }
        connection.start(queue: queue)
    }

    @objc func printPdf(_ call: CAPPluginCall) {
        guard let base64 = call.getString("base64"), let pdf = Data(base64Encoded: base64) else {
            call.reject("Invalid PDF")
            return
        }

        DispatchQueue.main.async {
            guard let view = self.bridge?.viewController?.view else {
                call.reject("No view to present the print dialog")
                return
            }
            let printInfo = UIPrintInfo.printInfo()
            printInfo.outputType = .general
            printInfo.jobName = call.getString("jobName") ?? "PICHA Ticket"

            let controller = UIPrintInteractionController.shared
            controller.printInfo = printInfo
            controller.printingItem = pdf
            let anchor = CGRect(x: view.bounds.midX, y: view.bounds.midY, width: 1, height: 1)
            controller.present(from: anchor, in: view, animated: true) { _, completed, error in
                if let error = error {
                    call.reject(error.localizedDescription)
                } else {
                    call.resolve(["completed": completed])
                }
            }
        }
    }
}
