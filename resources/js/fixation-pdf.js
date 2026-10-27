// /* Fixation PDF: readable A4 pages. Never downscale the whole official form to one page. */
// import html2canvas from 'html2canvas';
// import { jsPDF } from 'jspdf';

// function choosePageEnd(sheet, pageTop, desiredEnd, maxEnd, canvasScale) {
//     let end = Math.min(desiredEnd, maxEnd);
//     if (end >= maxEnd) return maxEnd;
//     const sheetTop = sheet.getBoundingClientRect().top;
//     const blocks = sheet.querySelectorAll(
//         '.official-preview-table tr, .kau-employee-signature, .kau-finance-section, .kau-finance-signatures'
//     );
//     for (const block of blocks) {
//         const rect = block.getBoundingClientRect();
//         const start = (rect.top - sheetTop) * canvasScale;
//         const finish = (rect.bottom - sheetTop) * canvasScale;
//         // If a row or signature block would be cut, prefer breaking immediately before it.
//         if (start > pageTop + 60 && start < end && finish > end) end = Math.round(start);
//     }
//     return end;
// }

// async function buildFixationPdf(autoPrint = false) {
//     const sheet = document.getElementById('officialFormContainer');
//     if (!sheet) throw new Error('Original official form not loaded.');
//         window.KAUFIX_APPLY_SERVER_CALC?.();
//         if (document.fonts?.ready) await document.fonts.ready;
//         // Render the form at an A4-compatible desktop width. The original
//         // narrow on-screen sheet was >2x as tall as it was wide, so fitting
//         // that image into one A4 page left ~39% of the page width unused.
//         // Only the canvas clone is resized; the live preview is untouched.
//         const captureWidth = 1120;
//         const canvas = await html2canvas(sheet, {
//             backgroundColor: '#ffffff', scale: 2, useCORS: true, logging: false,
//             scrollX: 0, scrollY: 0,
//             windowWidth: Math.max(1400, document.documentElement.clientWidth),
//             onclone: clonedDocument => {
//                 const clonedSheet = clonedDocument.getElementById('officialFormContainer');
//                 if (clonedSheet) {
//                     clonedSheet.style.setProperty('box-sizing', 'border-box', 'important');
//                     clonedSheet.style.setProperty('width', `${captureWidth}px`, 'important');
//                     clonedSheet.style.setProperty('max-width', 'none', 'important');
//                     clonedSheet.style.setProperty('min-width', `${captureWidth}px`, 'important');
//                 }
//             },
//         });
//         const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4', compress: true });
//         const pageWidth = pdf.internal.pageSize.getWidth();
//         const pageHeight = pdf.internal.pageSize.getHeight();
//         const margin = 6;
//         const widthMm = pageWidth - margin * 2;
//         const heightMm = pageHeight - margin * 2;
//         // Explicit single-page export: fit the original official form to one
//         // A4 canvas. jsPDF writes no browser-generated title/URL/page footer.
//         // The printed font size depends on the form's total source height;
//         // preview visually before using for signatures.
//         const fittedHeight = canvas.height * widthMm / canvas.width;
//         const factor = Math.min(1, heightMm / fittedHeight);
//         const finalWidth = widthMm * factor;
//         const finalHeight = fittedHeight * factor;
//         pdf.addImage(canvas.toDataURL('image/jpeg', 0.94), 'JPEG',
//             (pageWidth - finalWidth) / 2, (pageHeight - finalHeight) / 2,
//             finalWidth, finalHeight);
//         const code = String(window.KAU_DB_FIXATION?.employee_code ?? 'employee')
//             .replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 40) || 'employee';
//         if (autoPrint) pdf.autoPrint();
//         return {blob: pdf.output('blob'), filename: `KAU_Fixation_2026_${code}.pdf`};
// }

// window.KAU_EXPORT_FIXATION_PDF = buildFixationPdf;

// async function downloadFixationPdf(button) {
//     if (button.disabled) return;
//     const previousText = button.textContent;
//     button.disabled = true;
//     button.textContent = 'Preparing PDF…';
//     try {
//         const {blob, filename} = await buildFixationPdf();
//         const href = URL.createObjectURL(blob);
//         const link = document.createElement('a');
//         link.href = href; link.download = filename; document.body.appendChild(link);
//         link.click(); link.remove();
//         setTimeout(()=>URL.revokeObjectURL(href),120000);
//     } catch (error) {
//         console.error('Fixation PDF export failed', error);
//         alert('PDF export could not complete. Use the browser Print / Save as PDF option.');
//     } finally {
//         button.disabled = false;
//         button.textContent = previousText;
//     }
// }

// document.addEventListener('click', event => {
//     const button = event.target.closest('[data-kau-download-pdf]');
//     if (button) {
//         event.preventDefault();
//         downloadFixationPdf(button);
//     }
// });

// // Direct report links on both pages invoke this same canonical A4 exporter.
// // No signature/payment permission is changed by exporting a provisional PDF.
// async function runRequestedPdfAction() {
//     const mode = new URLSearchParams(window.location.search).get('pdf');
//     if (!['download', 'print'].includes(mode)) return;
//     try {
//         if (document.readyState !== 'complete') {
//             await new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve, {once:true}));
//         }
//         if (document.fonts?.ready) await document.fonts.ready;
//         const {blob, filename} = await buildFixationPdf(mode === 'print');
//         const url = URL.createObjectURL(blob);
//         if (mode === 'print') {
//             // Browser PDF viewer controls the final print dialog, not a second
//             // HTML renderer with potentially different page breaks.
//             window.location.replace(url);
//         } else {
//             const link = document.createElement('a');
//             link.href = url;
//             link.download = filename;
//             document.body.appendChild(link);
//             link.click();
//             link.remove();
//             setTimeout(() => URL.revokeObjectURL(url), 120000);
//         }
//     } catch (error) {
//         console.error('Canonical salary fixation PDF failed', error);
//         alert('PDF export failed. Use the same original-form preview and print from there.');
//     }
// }
// runRequestedPdfAction();



/*
 * KAU EMS — Canonical Salary Fixation Print
 *
 * Uses the original kaufixation.html document.
 * Does not convert the report into a JPEG.
 * Does not modify fixation calculations.
 * Does not approve or post any financial transaction.
 */

function waitForDocument(frame) {
    return new Promise((resolve, reject) => {
        const timeout = window.setTimeout(() => {
            reject(new Error('Fixation document loading timed out.'));
        }, 30000);

        frame.onload = () => {
            window.clearTimeout(timeout);
            resolve();
        };

        frame.onerror = () => {
            window.clearTimeout(timeout);
            reject(new Error('Could not load fixation document.'));
        };
    });
}

async function printFixationDocument(url) {
    const existing = document.getElementById(
        'kau-canonical-fixation-print-frame'
    );

    if (existing) {
        existing.remove();
    }

    const frame = document.createElement('iframe');

    frame.id = 'kau-canonical-fixation-print-frame';
    frame.title = 'Salary Fixation Print Document';

    frame.style.position = 'fixed';
    frame.style.width = '1px';
    frame.style.height = '1px';
    frame.style.left = '-10000px';
    frame.style.top = '0';
    frame.style.border = '0';
    frame.style.opacity = '0';

    document.body.appendChild(frame);

    const loading = waitForDocument(frame);

    frame.src = url;

    try {
        await loading;

        const contentWindow = frame.contentWindow;
        const contentDocument = frame.contentDocument;

        if (!contentWindow || !contentDocument) {
            throw new Error('Print frame is unavailable.');
        }

        if (contentDocument.fonts?.ready) {
            await contentDocument.fonts.ready;
        }

        if (contentWindow.KAUFIX_APPLY_SERVER_CALC) {
            contentWindow.KAUFIX_APPLY_SERVER_CALC();
        }

        const images = Array.from(contentDocument.images);

        await Promise.all(
            images.map(image => {
                if (image.complete) {
                    return Promise.resolve();
                }

                return new Promise(resolve => {
                    image.addEventListener('load', resolve, {
                        once: true
                    });

                    image.addEventListener('error', resolve, {
                        once: true
                    });
                });
            })
        );

        await new Promise(resolve => {
            contentWindow.requestAnimationFrame(() => {
                contentWindow.requestAnimationFrame(resolve);
            });
        });

        const sheet = contentDocument.getElementById(
            'officialFormContainer'
        );

        if (!sheet) {
            throw new Error('Official fixation form was not found.');
        }

        contentWindow.focus();
        contentWindow.print();

    } catch (error) {
        frame.remove();
        console.error('KAU Fixation Print Error:', error);
        alert('Could not prepare the fixation report.');
    }
}

window.KAU_PRINT_CANONICAL_FIXATION = printFixationDocument;

document.addEventListener('click', event => {
    const button = event.target.closest(
        '[data-kau-download-pdf]'
    );

    if (!button) {
        return;
    }

    event.preventDefault();

    const url = button.dataset.kauPrintUrl;

    if (!url) {
        console.error('Missing fixation print URL.');
        return;
    }

    printFixationDocument(url);
});
