Набор методов которые позволят осуществлять поиск по модели. В основном данный **Trait** интегрирован в каждую модель, поэтому вам всегда доступны все ниже описанные методы поиска или фильтрации. 

Шаблон находиться в **App\Traits\TemplateFilter**, он может работать только с **Illuminate\Database\Eloquent**, поэтому мы его подключаем только в **Model**. Доступны исключения когда мы можем использовать **Trait** в любом месте, но для его работы нам нужно будет передать **Model** в метод **search**.

```php
use TemplateFilter;

$contact = $this->search(Contact::class);
```

### Параметры
Доступны такие параметры. Каждый метод может работать со связью если она есть в модели, до бесконечности например: **search[contact][contact_types][…………….][id] = 1**. Каждый поиск можно использовать совместно с другим.

* search[ключ] = значения.
* search[ключ] = значения.
* either[ключ] = значения.
* global-search = значения.
* has[ключ] = true.
* doesnt[ключ] = true.

### Search
Метод, который осуществляет поиск по модели, с помощью **ILIKE**. Так как поиск осуществляться через **ilike** то он будет искать не строгое совпадения. Так же только для модели или метода contact доступен параметр **full_name (это поиск по ФИО)**. Что бы его использовать нужно в параметр передать (как пример).
> search[contact][contact_types]=1 

> search[contact][full_name]=Вася

> search[id]=10

### Filter
Строгий поиск, но у него есть множество возможностей. Что бы его использовать нужно в параметр передать.
> filter[contact][contact_types]=1

> filter[id]=10

> filter[id]=10;11

> filter[id]=<|10

Есть набор знаков которые помогут в поиске.

<table>
    <colgroup>
        <col style="width: 48px;">
        <col style="width: 77px;text-align: center">
        <col style="width: 100%">
    </colgroup>
    <tbody>
    <tr>
        <th rowspan="1" colspan="1">
            <div><p><strong>№</strong></p></div>
        </th>
        <th rowspan="1" colspan="1">
            <div><p><strong>Знак</strong></p></div>
        </th>
        <th rowspan="1" colspan="1"><p><strong>Пример</strong></p>
        </th>
    </tr>
    <tr>
        <td>
            <div><p><strong>1.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span style="color: rgb(255, 86, 48);">;</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p><span
                >filter[id]= 10;11</span></p></td>
    </tr>
    <tr>
        <td>
            <div><p><strong>2.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span
                                style="color: rgb(255, 86, 48);">&lt;</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p><span
                >filter[id]= &lt;|10</span></p></td>
    </tr>
    <tr>
        <td>
            <div><p><strong>3.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span
                                style="color: rgb(255, 86, 48);">&gt;</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p><span
                >filter[id]= &gt;|10</span></p></td>
    </tr>
    <tr>
        <td>
            <div><p><strong>4.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span
                                style="color: rgb(255, 86, 48);">&lt;=</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p><span
                >filter[id]= &lt;=|10</span></p></td>
    </tr>
    <tr>
        <td>
            <div><p><strong>5.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span
                                style="color: rgb(255, 86, 48);">&gt;=</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p><span
                >filter[id]= &gt;=|10</span></p></td>
    </tr>
    <tr>
        <td>
            <div><p><strong>6.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span
                                style="color: rgb(255, 86, 48);">&lt;&gt;</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p><span
                >filter[id]= &lt;&gt;|10</span></p></td>
    </tr>
    <tr>
        <td>
            <div><p><strong>7.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span
                                style="color: rgb(255, 86, 48);">!=</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p><span
                >filter[id]= !=|10</span></p></td>
    </tr>
    <tr>
        <td>
            <div><p><strong>8.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span
                                style="color: rgb(255, 86, 48);">=</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p><span
                >filter[id]= =|10</span></p></td>
    </tr>
    <tr>
        <td>
            <div><p><strong>9.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span
                                style="color: rgb(255, 86, 48);">&amp;</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p>Данный знак
                доступен только при варианте <span
                >filter[id]=&lt;|10&amp;&gt;|20</span></p>
        </td>
    </tr>
    <tr>
        <td>
            <div><p><strong>10.</strong></p></div>
        </td>
        <td rowspan="1" colspan="1">
            <div><p><strong><span
                                style="color: rgb(255, 86, 48);">||</span></strong>
                </p></div>
        </td>
        <td rowspan="1" colspan="1"><p>Данный знак
                доступен только при варианте <span
                >filter[id]=&lt;|10||&gt;|20</span></p></td>
    </tr>
    </tbody>
</table>