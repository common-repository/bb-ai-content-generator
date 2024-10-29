jQuery(document).ready(function($) {
    // Form ve diğer öğeleri tanımlayın
    var $form = $('#content-generator-form');
    var $results = $('#results');
    var $progressContainer = $('#progress-container');
    var $progressBar = $('#progress');
    var $progressStatus = $('#progress-status');
    var $createDraftButton = $('#create-draft');
    var userTopic = ''; // Kullanıcının girdiği ana konuyu saklamak için

    // Form gönderildiğinde çalıştırılacak işlev
    $form.on('submit', function(e) {
        e.preventDefault(); // Sayfanın yeniden yüklenmesini önler
    
        // API anahtarının girilip girilmediğini kontrol et
        if (!bb_ai_content_generator_ajax.api_key_set) {
            showError('API key not entered. Please enter your API key from the API Settings page.');
            return;
        }
    
        $results.empty(); // Sonuçları temizler
        $progressContainer.show(); // İlerleme çubuğunu gösterir
        $createDraftButton.hide(); // "Taslak Oluştur" butonunu gizler
        updateProgress(0, 'Headlines are being generated...');
    
        userTopic = $('#topic').val(); // Kullanıcının girdiği konuyu sakla
        var titleCount = $('#title-count').val();
    
        generateTitles(userTopic, titleCount); // Başlık oluşturma sürecini başlat
    });

    // "Taslak Oluştur" butonuna tıklandığında çalıştırılacak işlev
    $createDraftButton.on('click', function() {
        var $content = $('<div>').html($results.html()); // Sonuçları kopyala
        var $h1 = $content.find('h1').first(); // İlk H1 öğesini bul
        var title = $h1.text(); // Başlığı al
        $h1.remove(); // H1'i içerikten kaldır
        var content = $content.html(); // Geri kalan içeriği al

        // AJAX isteği ile taslak oluştur
        $.ajax({
            url: bb_ai_content_generator_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'create_draft_post',
                nonce: bb_ai_content_generator_ajax.nonce,
                title: title,
                content: content
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message); // Başarılı mesajını göster
                    window.location.href = response.data.edit_url; // Taslağı düzenleme sayfasına yönlendir
                } else {
                    showError('An error occurred while generating the draft: ' + response.data); // Hata mesajını göster
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                showError('Server error: ' + textStatus + ' - ' + errorThrown); // Sunucu hatasını göster
            }
        });
    });

    // Başlıkları oluşturmak için AJAX isteği yapan işlev
    function generateTitles(topic, count) {
        $.ajax({
            url: bb_ai_content_generator_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_titles',
                nonce: bb_ai_content_generator_ajax.nonce,
                topic: topic,
                count: count
            },
            success: function(response) {
                if (response.success) {
                    var titles = response.data; // Başlıkları al
                    updateProgress(20, 'Titles have been generated. Sections are being created...');
                    generateSectionsForTitlesSequentially(titles); // Bölümleri oluştur
                } else {
                    showError('An error occurred while generating the titles: ' + response.data); // Hata mesajını göster
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                showError('Server error: ' + textStatus + ' - ' + errorThrown); // Sunucu hatasını göster
            }
        });
    }

    // Başlıklar için bölümleri sırasıyla oluşturmak için işlev
    function generateSectionsForTitlesSequentially(titles) {
        var index = 0;
        var titlesWithSections = [];

        // Sıradaki bölümü oluşturma işlevi
        function generateNextSection() {
            if (index < titles.length) {
                var title = titles[index];
                $.ajax({
                    url: bb_ai_content_generator_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'generate_sections',
                        nonce: bb_ai_content_generator_ajax.nonce,
                        title: title
                    },
                    success: function(response) {
                        if (response.success) {
                            titlesWithSections.push({
                                title: title,
                                sections: response.data
                            });
                            index++;
                            var progress = 20 + (index / titles.length) * 30; // İlerleme yüzdesini güncelle
                            updateProgress(progress, 'Sections are being created... (' + index + '/' + titles.length + ')');
                            generateNextSection(); // Sıradaki bölümü oluştur
                        } else {
                            showError('An error occurred while generating sections: ' + response.data); // Hata mesajını göster
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        showError('An error occurred while creating the sections: ' + textStatus + ' - ' + errorThrown); // Sunucu hatasını göster
                    }
                });
            } else {
                generateParagraphsForSectionsSequentially(titlesWithSections); // Paragrafları oluştur
            }
        }

        generateNextSection(); // İlk bölümü oluştur
    }

    // Bölümler için paragrafları sırasıyla oluşturmak için işlev
    function generateParagraphsForSectionsSequentially(titlesWithSections) {
        var titleIndex = 0;
        var sectionIndex = 0;

        // Sıradaki paragrafı oluşturma işlevi
        function generateNextParagraph() {
            if (titleIndex < titlesWithSections.length) {
                var titleData = titlesWithSections[titleIndex];
                if (sectionIndex < titleData.sections.length) {
                    var section = titleData.sections[sectionIndex];
                    $.ajax({
                        url: bb_ai_content_generator_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'generate_paragraphs',
                            nonce: bb_ai_content_generator_ajax.nonce,
                            title: titleData.title,
                            section: section
                        },
                        success: function(response) {
                            if (response.success) {
                                appendContent(titleData.title, section, response.data); // İçeriği ekle
                                sectionIndex++;
                                var totalSections = titlesWithSections.reduce(function(sum, title) {
                                    return sum + title.sections.length;
                                }, 0);
                                var completedSections = titlesWithSections.slice(0, titleIndex).reduce(function(sum, title) {
                                    return sum + title.sections.length;
                                }, 0) + sectionIndex;
                                var progress = 50 + (completedSections / totalSections) * 50; // İlerleme yüzdesini güncelle
                                updateProgress(progress, 'Paragraphs are being generated... (' + completedSections + '/' + totalSections + ')');
                                generateNextParagraph(); // Sıradaki paragrafı oluştur
                            } else {
                                showError('An error occurred while generating the paragraph: ' + response.data); // Hata mesajını göster
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            showError('Server error: ' + textStatus + ' - ' + errorThrown); // Sunucu hatasını göster
                        }
                    });
                } else {
                    titleIndex++;
                    sectionIndex = 0;
                    generateNextParagraph(); // Sıradaki başlığa geç
                }
            } else {
                updateProgress(100, 'Content generation completed!'); // İlerleme tamamlandı
                setTimeout(function() {
                    $progressContainer.hide(); // İlerleme çubuğunu gizle
                    finalizeContent(); // İçeriği sonlandır
                }, 2000);
            }
        }

        generateNextParagraph(); // İlk paragrafı oluştur
    }

    // İçeriği eklemek için işlev
    function appendContent(title, section, paragraph) {
        if ($results.children().length === 0) {
            // İlk olarak kullanıcının girdiği konuyu H1 olarak ekle
            $results.append($('<h1>').text(userTopic));
        }
        
        // Başlığı H2 olarak ekle, sadece ilk kez
        if ($results.find('h2:contains("' + cleanTitle(title) + '")').length === 0) {
            $results.append($('<h2>').text(cleanTitle(title)));
        }
        
        // Bölüm başlığı H3 olarak eklenir
        $results.append($('<h3>').text(cleanTitle(section)));
        $results.append($('<p>').text(paragraph)); // Paragrafı ekle
    }

    // Başlıktaki gereksiz karakterleri temizlemek için işlev
    function cleanTitle(title) {
        return title.replace(/^\d+\.\s*/, '').replace(/^\"|\"$/g, '').trim(); // Sayıları ve gereksiz işaretleri kaldır
    }

    // İçeriği düzenlemek ve sonlandırmak için işlev
    function finalizeContent() {
        var $content = $results.clone(); // Sonuçları kopyala
        
        // H1 başlığını koruyarak diğer başlıkları temizle
        $content.find('h2, h3').each(function() {
            $(this).text(cleanTitle($(this).text()));
        });

        // İçeriği güncelle
        $results.html($content.html());

        // "Taslak Oluştur" butonunu göster
        $createDraftButton.show();
    }

    // İlerleme durumunu güncellemek için işlev
    function updateProgress(percentage, status) {
        $progressBar.css('width', percentage + '%'); // İlerleme çubuğunu güncelle
        $progressStatus.text(status); // Durum metnini güncelle
    }

    // Hata mesajını göstermek için işlev
    function showError(message) {
        $results.append($('<p>').addClass('error').text(message)); // Hata mesajını ekle
        $progressContainer.hide(); // İlerleme çubuğunu gizle
        $createDraftButton.hide(); // "Taslak Oluştur" butonunu gizle
    }

    // API Anahtarı giriş alanını yönetmek için işlevler
    var $apiKeyInput = $('#bb_ai_content_generator_api_key');
    if ($apiKeyInput.length > 0) {
        var originalValue;
        
        $apiKeyInput.on('focus', function() {
            originalValue = this.value;
            if (this.value.includes('*')) {
                this.value = '';
            }
        });
    
        $apiKeyInput.on('blur', function() {
            if (this.value === '') {
                this.value = originalValue;
            }
        });
    
        $apiKeyInput.closest('form').on('submit', function(e) {
            var newValue = $apiKeyInput.val();
            if (newValue === originalValue || newValue === '') {
                $apiKeyInput.val(originalValue); // Değişiklik yoksa veya boşsa orijinal değeri kullan
            }
        });
    }
});
