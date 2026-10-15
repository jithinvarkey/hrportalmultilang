type ImageDataArray = Uint8ClampedArray<ArrayBuffer>;

declare module 'pdfjs-dist/legacy/build/pdf.mjs' {
  export const GlobalWorkerOptions: { workerSrc: string };
  export function getDocument(source: { data: ArrayBuffer }): {
    promise: Promise<any>;
  };
}
