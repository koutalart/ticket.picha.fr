import {registerPlugin} from "@capacitor/core";

export const ZEBRA_RAW_PORT = 9100;

export interface PichaPrinterPlugin {
    sendRaw(options: { host: string; port: number; data: string; timeoutMs?: number }): Promise<void>;

    printPdf(options: { base64: string; jobName: string }): Promise<{ completed: boolean }>;
}

/** Native side: ios/App/App/PichaPrinterPlugin.swift */
export const PichaPrinter = registerPlugin<PichaPrinterPlugin>('PichaPrinter');

const blobToBase64 = (blob: Blob): Promise<string> => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] ?? '');
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
});

export const sendZplToLanPrinter = (host: string, zpl: string) =>
    PichaPrinter.sendRaw({host, port: ZEBRA_RAW_PORT, data: zpl, timeoutMs: 5000});

export const printPdfWithAirPrint = async (pdf: Blob, jobName: string) =>
    PichaPrinter.printPdf({base64: await blobToBase64(pdf), jobName});
