import { Camera, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

type Phase =
    | 'idle'
    | 'starting'
    | 'live'
    | 'captured'
    | 'denied'
    | 'unsupported';

export type SelfieCaptureMeta = {
    width: number;
    height: number;
    facing: 'user';
};

/**
 * A selfie taken now, with the camera — and only that.
 *
 * There is no file picker here on purpose: the point of a live selfie is that it
 * is not a photograph someone already had. The browser hands over a still frame
 * from the camera stream, which is sent as a JPEG with the frame's size. (The
 * server can only take the browser's word that it came from the camera; the
 * reviewer still looks at the face. Nothing here analyses the face.)
 *
 * The stream is stopped as soon as the frame is taken and whenever this is
 * unmounted, so the camera light never stays on.
 */
export default function SelfieCapture({
    onCapture,
    saving = false,
    disabled = false,
    onCancel,
}: {
    onCapture: (file: File, meta: SelfieCaptureMeta) => void;
    saving?: boolean;
    disabled?: boolean;
    /** Leave a retake and keep the selfie already saved. */
    onCancel?: () => void;
}) {
    const { t } = useTranslation();
    const videoRef = useRef<HTMLVideoElement>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const [phase, setPhase] = useState<Phase>('idle');
    const [frame, setFrame] = useState<{
        file: File;
        url: string;
        meta: SelfieCaptureMeta;
    } | null>(null);

    const stop = useCallback(() => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
    }, []);

    useEffect(
        () => () => {
            stop();
        },
        [stop],
    );

    useEffect(
        () => () => {
            if (frame) {
                URL.revokeObjectURL(frame.url);
            }
        },
        [frame],
    );

    const start = async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            setPhase('unsupported');

            return;
        }

        setPhase('starting');
        setFrame(null);

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user',
                    width: { ideal: 1280 },
                    height: { ideal: 960 },
                },
                audio: false,
            });

            streamRef.current = stream;

            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                await videoRef.current.play();
            }

            setPhase('live');
        } catch {
            stop();
            setPhase('denied');
        }
    };

    const take = () => {
        const video = videoRef.current;

        if (!video || video.videoWidth === 0) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d')?.drawImage(video, 0, 0);

        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    return;
                }

                stop();
                setFrame({
                    file: new File([blob], 'selfie.jpg', {
                        type: 'image/jpeg',
                    }),
                    url: URL.createObjectURL(blob),
                    meta: {
                        width: canvas.width,
                        height: canvas.height,
                        facing: 'user',
                    },
                });
                setPhase('captured');
            },
            'image/jpeg',
            0.9,
        );
    };

    const cancel = () => {
        stop();
        setFrame(null);
        setPhase('idle');
    };

    if (phase === 'unsupported' || phase === 'denied') {
        return (
            <div className="space-y-2">
                <p className="text-danger text-sm" role="alert">
                    {t(
                        phase === 'denied'
                            ? 'kyc.batch_form.camera_denied'
                            : 'kyc.batch_form.camera_unsupported',
                    )}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={start}
                    disabled={disabled}
                >
                    <RefreshCw className="size-4" aria-hidden="true" />
                    {t('kyc.batch_form.camera_retry')}
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {(phase === 'starting' ||
                phase === 'live' ||
                phase === 'captured') && (
                <div className="bg-muted relative aspect-[4/3] w-full max-w-sm overflow-hidden rounded-xl border">
                    {/* Mirrored so it behaves like a mirror; the saved frame is not. */}
                    <video
                        ref={videoRef}
                        autoPlay
                        playsInline
                        muted
                        aria-label={t('kyc.batch_form.selfie_title')}
                        className={`size-full -scale-x-100 object-cover ${phase === 'captured' ? 'hidden' : ''}`}
                    />
                    {phase === 'captured' && frame && (
                        <img
                            src={frame.url}
                            alt={t('kyc.batch_form.selfie_title')}
                            className="size-full -scale-x-100 object-cover"
                        />
                    )}
                    {phase === 'starting' && (
                        <div className="absolute inset-0 flex items-center justify-center gap-2 text-sm">
                            <Spinner />
                            {t('kyc.batch_form.camera_busy')}
                        </div>
                    )}
                </div>
            )}

            <div className="flex flex-wrap gap-2">
                {phase === 'idle' && (
                    <>
                        <Button
                            type="button"
                            onClick={start}
                            disabled={disabled}
                        >
                            <Camera className="size-4" aria-hidden="true" />
                            {t('kyc.batch_form.selfie_start')}
                        </Button>
                        {onCancel && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onCancel}
                            >
                                {t('kyc.batch_form.selfie_cancel')}
                            </Button>
                        )}
                    </>
                )}

                {phase === 'live' && (
                    <>
                        <Button type="button" onClick={take}>
                            <Camera className="size-4" aria-hidden="true" />
                            {t('kyc.batch_form.selfie_take')}
                        </Button>
                        <Button type="button" variant="ghost" onClick={cancel}>
                            {t('kyc.batch_form.selfie_cancel')}
                        </Button>
                    </>
                )}

                {phase === 'captured' && frame && (
                    <>
                        <Button
                            type="button"
                            disabled={saving}
                            onClick={() => onCapture(frame.file, frame.meta)}
                        >
                            {saving && <Spinner />}
                            {saving
                                ? t('kyc.batch_form.selfie_saving')
                                : t('kyc.batch_form.selfie_use')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            onClick={start}
                        >
                            <RefreshCw className="size-4" aria-hidden="true" />
                            {t('kyc.batch_form.selfie_retake')}
                        </Button>
                    </>
                )}
            </div>
        </div>
    );
}
