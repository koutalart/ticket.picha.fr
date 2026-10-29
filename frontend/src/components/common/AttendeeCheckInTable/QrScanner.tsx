import {ReactNode, useEffect, useRef, useState} from 'react';
import QrScanner from 'qr-scanner';
import classes from './QrScanner.module.scss';
import {showError} from "../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {QrScannerControls} from './QrScannerControls';
import {PermissionDeniedMessage} from './PermissionDeniedMessage';

/**
 * Result of a scan given back by the parent: it then plays the sounds itself and the overlay shows the real outcome.
 * 'ignored' means the parent took over (e.g. opened a dialog).
 */
export type QrScanOutcome = 'success' | 'error' | 'ignored';

interface QRScannerComponentProps {
    onAttendeeScanned: (attendeePublicId: string) => void | Promise<QrScanOutcome | void>;
    onClose: () => void;
    isSoundOn?: boolean;
    topContent?: ReactNode;
}

const MAX_SCANS_PER_SECOND = 15;
const SAME_CODE_COOLDOWN_MS = 3000;
const FEEDBACK_MS = 400;
const SCAN_REGION_RATIO = 0.8;
const SCAN_DOWNSCALED_SIZE = 600;

type Feedback = 'success' | 'failure' | 'checkingIn' | null;

export const QRScannerComponent = (props: QRScannerComponentProps) => {
    const videoRef = useRef<HTMLVideoElement>(null);
    const qrScannerRef = useRef<QrScanner | null>(null);
    const [permissionDenied, setPermissionDenied] = useState(false);
    const [isFlashAvailable, setIsFlashAvailable] = useState(false);
    const [isFlashOn, setIsFlashOn] = useState(false);
    const [cameraList, setCameraList] = useState<QrScanner.Camera[]>();
    const [feedback, setFeedback] = useState<Feedback>(null);

    const processedCodesRef = useRef<Set<string>>(new Set());
    const lastCodeRef = useRef<string | null>(null);
    const lastCodeAtRef = useRef(0);
    const busyRef = useRef(false);
    const feedbackTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const onScannedRef = useRef(props.onAttendeeScanned);

    const scanSuccessAudioRef = useRef<HTMLAudioElement | null>(null);
    const scanErrorAudioRef = useRef<HTMLAudioElement | null>(null);

    const [isSoundOn, setIsSoundOn] = useState(() => {
        if (props.isSoundOn !== undefined) {
            return props.isSoundOn;
        }
        const storedIsSoundOn = localStorage.getItem("scannerSoundOn");
        return storedIsSoundOn === null ? true : JSON.parse(storedIsSoundOn);
    });
    const isSoundOnRef = useRef(isSoundOn);

    useEffect(() => {
        onScannedRef.current = props.onAttendeeScanned;
    }, [props.onAttendeeScanned]);

    useEffect(() => {
        if (props.isSoundOn !== undefined) {
            setIsSoundOn(props.isSoundOn);
        }
    }, [props.isSoundOn]);

    useEffect(() => {
        isSoundOnRef.current = isSoundOn;
        if (props.isSoundOn === undefined) {
            localStorage.setItem("scannerSoundOn", JSON.stringify(isSoundOn));
        }
    }, [isSoundOn, props.isSoundOn]);

    const play = (audio: HTMLAudioElement | null) => {
        if (!isSoundOnRef.current || !audio) {
            return;
        }
        audio.currentTime = 0;
        audio.play().catch(() => {
            // The browser may refuse to play before a user gesture.
        });
    };

    const showFeedback = (value: Feedback, duration: number | null = FEEDBACK_MS) => {
        if (feedbackTimeoutRef.current) {
            clearTimeout(feedbackTimeoutRef.current);
        }
        setFeedback(value);
        if (duration !== null) {
            feedbackTimeoutRef.current = setTimeout(() => setFeedback(null), duration);
        }
    };

    /**
     * Called for every frame where a code is read (up to 15 per second): the same code is ignored for 3 seconds,
     * a code already checked in during this session is reported once per cooldown, any other code is sent at once.
     */
    const handleDecoded = async (code: string) => {
        const now = Date.now();
        if (busyRef.current) {
            return;
        }
        if (code === lastCodeRef.current && now - lastCodeAtRef.current < SAME_CODE_COOLDOWN_MS) {
            return;
        }
        lastCodeRef.current = code;
        lastCodeAtRef.current = now;

        if (processedCodesRef.current.has(code)) {
            showError(t`You already scanned this ticket`);
            showFeedback('failure');
            play(scanErrorAudioRef.current);
            return;
        }

        busyRef.current = true;
        showFeedback('checkingIn', null);

        try {
            const outcome = await onScannedRef.current(code);

            if (outcome === undefined) {
                processedCodesRef.current.add(code);
                showFeedback('success');
                play(scanSuccessAudioRef.current);
            } else if (outcome === 'success') {
                processedCodesRef.current.add(code);
                showFeedback('success');
            } else if (outcome === 'error') {
                showFeedback('failure');
            } else {
                showFeedback(null);
            }
        } catch {
            showFeedback('failure');
            play(scanErrorAudioRef.current);
        } finally {
            busyRef.current = false;
            lastCodeAtRef.current = Date.now();
        }
    };

    const handleDecodedRef = useRef(handleDecoded);
    handleDecodedRef.current = handleDecoded;

    const enableContinuousFocus = () => {
        const stream = videoRef.current?.srcObject;
        if (!(stream instanceof MediaStream)) {
            return;
        }
        const [track] = stream.getVideoTracks();
        const capabilities = (track?.getCapabilities?.() || {}) as MediaTrackCapabilities & { focusMode?: string[] };
        if (track && capabilities.focusMode?.includes('continuous')) {
            track.applyConstraints({advanced: [{focusMode: 'continuous'} as MediaTrackConstraintSet]}).catch(() => {
                // Not supported by this camera.
            });
        }
    };

    const startScanner = async () => {
        try {
            const probe = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}});
            probe.getTracks().forEach((track) => track.stop());

            if (videoRef.current) {
                qrScannerRef.current = new QrScanner(videoRef.current, (result) => {
                    handleDecodedRef.current(result.data);
                }, {
                    maxScansPerSecond: MAX_SCANS_PER_SECOND,
                    preferredCamera: 'environment',
                    returnDetailedScanResult: true,
                    calculateScanRegion: (video) => {
                        const size = Math.round(Math.min(video.videoWidth, video.videoHeight) * SCAN_REGION_RATIO);
                        const downScaled = Math.min(size, SCAN_DOWNSCALED_SIZE);
                        return {
                            x: Math.round((video.videoWidth - size) / 2),
                            y: Math.round((video.videoHeight - size) / 2),
                            width: size,
                            height: size,
                            downScaledWidth: downScaled,
                            downScaledHeight: downScaled,
                        };
                    },
                });
                await qrScannerRef.current.start();
                enableContinuousFocus();
            }
        } catch (error) {
            setPermissionDenied(true);
            console.error(error);
        }
    };

    const stopScanner = () => {
        if (qrScannerRef.current) {
            qrScannerRef.current.stop();
            qrScannerRef.current.destroy();
            qrScannerRef.current = null;
        }
    };

    const handleClose = () => {
        stopScanner();
        props.onClose();
    };

    const handleFlashToggle = () => {
        if (!isFlashAvailable) {
            showError(t`Flash is not available on this device`);
            return;
        }
        if (qrScannerRef.current) {
            if (isFlashOn) {
                qrScannerRef.current.turnFlashOff();
            } else {
                qrScannerRef.current.turnFlashOn();
            }
            setIsFlashOn(!isFlashOn);
        }
    };

    const handleSoundToggle = () => {
        setIsSoundOn(!isSoundOn);
    };

    const requestPermission = async () => {
        setPermissionDenied(false);
        await startScanner();
    };

    const updateFlashAvailability = async () => {
        if (qrScannerRef.current) {
            const hasFlash = await qrScannerRef.current.hasFlash();
            setIsFlashAvailable(hasFlash);
        }
    };

    useEffect(() => {
        startScanner().then(() => {
            updateFlashAvailability().catch(console.error);
            QrScanner.listCameras(true)
                .then(cameras => setCameraList(cameras));
        });

        return () => {
            stopScanner();
            if (feedbackTimeoutRef.current) {
                clearTimeout(feedbackTimeoutRef.current);
            }
        };
    }, []);

    const handleCameraSelection = (camera: QrScanner.Camera) => {
        return qrScannerRef.current?.setCamera(camera.id)
            .then(() => {
                enableContinuousFocus();
                return updateFlashAvailability().catch(console.error);
            });
    };

    return (
        <div className={classes.videoContainer}>
            {permissionDenied && (
                <PermissionDeniedMessage
                    onRequestPermission={requestPermission}
                    onClose={handleClose}
                />
            )}

            <video className={classes.video} ref={videoRef} playsInline muted></video>

            {props.topContent}

            <QrScannerControls
                isFlashAvailable={isFlashAvailable}
                isFlashOn={isFlashOn}
                isSoundOn={isSoundOn}
                cameraList={cameraList}
                onFlashToggle={handleFlashToggle}
                onSoundToggle={handleSoundToggle}
                onCameraSelect={handleCameraSelection}
                onClose={handleClose}
            />

            <audio ref={scanSuccessAudioRef} src="/sounds/scan-success.wav" preload="auto"/>
            <audio ref={scanErrorAudioRef} src="/sounds/scan-error.wav" preload="auto"/>

            <div className={`${classes.scannerOverlay} ${feedback === 'success' ? classes.success : ""} ${feedback === 'failure' ? classes.failure : ""} ${feedback === 'checkingIn' ? classes.checkingIn : ""}`}/>
        </div>
    );
};
